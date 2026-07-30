<?php

namespace App\Domain\Customers;

use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Services\Shopify\Orders\ShopifyOrderTags;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\WooCommerce\Orders\WooOrderTags;
use App\Services\WooCommerce\WooClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * A customer's orders, read from the store, with the LETS ones marked.
 *
 * ALL of their orders — not only the ones this app created. The merchant is
 * asking "what has this person bought"; answering with our slice alone would
 * present a fragment as the whole history.
 *
 * "Created by LETS" is decided two ways, and either is enough:
 *
 *  1. The mark the order carries (Woo `lets_tags`/`lets_order_role` meta, the
 *     Shopify umbrella tag).
 *  2. Our own records — the payment ledger and the gift recipients know which
 *     order ids we produced.
 *
 * Both, because the mark only exists on orders created since tagging shipped. An
 * older subscription cycle is still ours, and a history that called it a plain
 * sale would be wrong about the merchant's own numbers.
 *
 * Never throws: a store that cannot be reached leaves the panel empty with a
 * reason, not a 500 on the customer page.
 */
final class CustomerOrdersReader
{
    // === CONSTANTS ===
    /** How many of the customer's most recent orders to show. */
    public const LIMIT = 25;

    /** Shopify's wording when a field sits behind protected customer data. */
    private const PROTECTED_MARKER = 'not approved to use the';

    /**
     * @return array{orders: array<int, array<string, mixed>>, reason: ?string}
     */
    public function read(Shop $shop, string $customerRef): array
    {
        try {
            return $shop->platform === Shop::PLATFORM_WOOCOMMERCE
                ? $this->fromWoo($shop, $customerRef)
                : $this->fromShopify($shop, $customerRef);
        } catch (\Throwable $e) {
            Log::warning('customers.orders.read_failed', [
                'shop_id' => $shop->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $this->nothing(CustomerContact::REASON_UNAVAILABLE);
        }
    }

    /** @return array{orders: array<int, array<string, mixed>>, reason: ?string} */
    private function fromWoo(Shop $shop, string $customerRef): array
    {
        if (! $shop->hasWooConnection()) {
            return $this->nothing(CustomerContact::REASON_UNAVAILABLE);
        }

        $customerId = (int) $customerRef;
        if ((string) $customerId !== trim($customerRef) || $customerId <= 0) {
            // A guest's orders are not linked to an account in the store.
            return $this->nothing(CustomerContact::REASON_GUEST);
        }

        $ours = $this->ourOrderIds($customerRef);
        $orders = [];

        foreach (WooClientFactory::for($shop)->fetchCustomerOrders($customerId, self::LIMIT) as $order) {
            $id = (string) ($order['id'] ?? '');
            $meta = $this->wooMeta($order);

            $orders[] = [
                'id' => $id,
                'number' => (string) ($order['number'] ?? $id),
                'date' => (string) ($order['date_created'] ?? ''),
                'total' => (float) ($order['total'] ?? 0),
                'currency' => (string) ($order['currency'] ?? $shop->currency ?? 'ILS'),
                'status' => (string) ($order['status'] ?? ''),
                'from_lets' => isset($meta[WooOrderTags::META_TAGS])
                    || isset($meta[WooOrderTags::META_ROLE])
                    || in_array($id, $ours, true),
            ];
        }

        return ['orders' => $orders, 'reason' => null];
    }

    /** @return array{orders: array<int, array<string, mixed>>, reason: ?string} */
    private function fromShopify(Shop $shop, string $customerRef): array
    {
        if (! $shop->hasShopifyConnection()) {
            return $this->nothing(CustomerContact::REASON_UNAVAILABLE);
        }

        $ref = trim($customerRef);
        if ($ref === '' || ! (str_starts_with($ref, 'gid://') || ctype_digit($ref))) {
            return $this->nothing(CustomerContact::REASON_GUEST);
        }

        $gid = str_starts_with($ref, 'gid://') ? $ref : 'gid://shopify/Customer/'.$ref;
        $ours = $this->ourOrderIds($customerRef);
        $umbrella = ShopifyOrderTags::umbrella();

        try {
            $body = ShopifyClientFactory::for($shop)->graphql(
                'query customerOrders($id: ID!, $first: Int!) {
                    customer(id: $id) {
                        orders(first: $first, sortKey: CREATED_AT, reverse: true) {
                            nodes {
                                id name createdAt tags
                                currentTotalPriceSet { shopMoney { amount currencyCode } }
                                displayFinancialStatus
                            }
                        }
                    }
                }',
                ['id' => $gid, 'first' => self::LIMIT],
            );
        } catch (\Throwable $e) {
            return $this->nothing(
                str_contains($e->getMessage(), self::PROTECTED_MARKER)
                    ? CustomerContact::REASON_ACCESS_PENDING
                    : CustomerContact::REASON_UNAVAILABLE,
            );
        }

        $orders = [];
        foreach ((array) data_get($body, 'data.customer.orders.nodes', []) as $node) {
            $gidValue = (string) ($node['id'] ?? '');
            $numeric = str_contains($gidValue, '/') ? (string) \Illuminate\Support\Str::afterLast($gidValue, '/') : $gidValue;

            $orders[] = [
                'id' => $numeric,
                'number' => (string) ($node['name'] ?? $numeric),
                'date' => (string) ($node['createdAt'] ?? ''),
                'total' => (float) data_get($node, 'currentTotalPriceSet.shopMoney.amount', 0),
                'currency' => (string) data_get($node, 'currentTotalPriceSet.shopMoney.currencyCode', 'ILS'),
                'status' => (string) ($node['displayFinancialStatus'] ?? ''),
                'from_lets' => in_array($umbrella, (array) ($node['tags'] ?? []), true)
                    || in_array($numeric, $ours, true),
            ];
        }

        return ['orders' => $orders, 'reason' => null];
    }

    /**
     * Order ids this app is on record as having produced for the customer — the
     * ledger's charges and the gift campaigns' recipients. Catches orders created
     * before the store-side tagging existed.
     *
     * @return array<int, string>
     */
    private function ourOrderIds(string $customerRef): array
    {
        $fromLedger = PaymentLedger::query()
            ->where('shopify_customer_id', $customerRef)
            ->get(['shopify_order_id', 'parent_order_id'])
            ->flatMap(fn ($row): array => [(string) $row->shopify_order_id, (string) $row->parent_order_id]);

        $fromGifts = GiftRecipient::query()
            ->whereNotNull('external_order_id')
            ->pluck('external_order_id')
            ->map(static fn ($id): string => (string) $id);

        return $fromLedger->concat($fromGifts)->filter()->unique()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, string>
     */
    private function wooMeta(array $order): array
    {
        $out = [];
        foreach ((array) ($order['meta_data'] ?? []) as $meta) {
            $key = (string) ($meta['key'] ?? '');
            if ($key !== '') {
                $out[$key] = (string) ($meta['value'] ?? '');
            }
        }

        return $out;
    }

    /** @return array{orders: array<int, array<string, mixed>>, reason: string} */
    private function nothing(string $reason): array
    {
        return ['orders' => [], 'reason' => $reason];
    }
}
