<?php

namespace App\Domain\ShopifySubscriptions;

use App\Models\Shop;
use App\Models\SubscriptionContract;
use Illuminate\Support\Carbon;

/**
 * The ONE writer of subscription_contracts rows. Everything else reads.
 *
 * Feeds from two shapes of the same object: the webhook payload Shopify pushes
 * (subscription_contracts/create|update — snake_case, numeric admin ids) and the
 * GraphQL shape our own reads return (camelCase, GIDs). Both normalise through
 * upsert(), keyed on (shop_id, shopify_gid), so a webhook and a read-back can
 * never create two rows for one contract.
 *
 * The mirror NEVER decides anything. Unknown status → stored verbatim (Shopify
 * may add statuses; refusing to record reality would desync us further, and the
 * scanner only ever acts on BILLABLE_STATUSES anyway).
 */
final class ContractMirror
{
    // === CONSTANTS ===
    /** Shopify's GID prefix for subscription contracts. */
    private const GID_PREFIX = 'gid://shopify/SubscriptionContract/';

    /**
     * Upsert from a WEBHOOK payload (subscription_contracts/create|update).
     * Shopify sends `admin_graphql_api_id` plus snake_case fields.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fromWebhook(Shop $shop, array $payload): ?SubscriptionContract
    {
        $gid = (string) ($payload['admin_graphql_api_id'] ?? '');
        if ($gid === '' && ($payload['id'] ?? null) !== null) {
            $gid = self::GID_PREFIX.$payload['id'];
        }
        if ($gid === '') {
            return null; // nothing to key on — never guess which contract this is
        }

        return $this->upsert($shop, [
            'shopify_gid' => $gid,
            'status' => strtoupper((string) ($payload['status'] ?? SubscriptionContract::STATUS_ACTIVE)),
            'interval' => strtoupper((string) data_get($payload, 'billing_policy.interval', '')) ?: null,
            'interval_count' => max(1, (int) data_get($payload, 'billing_policy.interval_count', 1)),
            'next_billing_date' => $this->date($payload['next_billing_date'] ?? null),
            'currency' => (string) ($payload['currency_code'] ?? 'USD'),
            'shopify_customer_gid' => $this->customerGid($payload),
        ]);
    }

    /**
     * Upsert from a GRAPHQL node (our read-backs and the initial sync).
     *
     * @param  array<string, mixed>  $node
     */
    public function fromGraphQl(Shop $shop, array $node): ?SubscriptionContract
    {
        $gid = (string) ($node['id'] ?? '');
        if ($gid === '') {
            return null;
        }

        $price = data_get($node, 'deliveryPrice.amount');
        $lines = array_map(
            static fn (array $edge): array => [
                'title' => (string) data_get($edge, 'node.title', ''),
                'quantity' => (int) data_get($edge, 'node.quantity', 1),
                'amount' => (string) data_get($edge, 'node.currentPrice.amount', ''),
            ],
            (array) data_get($node, 'lines.edges', []),
        );

        return $this->upsert($shop, [
            'shopify_gid' => $gid,
            'status' => strtoupper((string) ($node['status'] ?? SubscriptionContract::STATUS_ACTIVE)),
            'interval' => strtoupper((string) data_get($node, 'billingPolicy.interval', '')) ?: null,
            'interval_count' => max(1, (int) data_get($node, 'billingPolicy.intervalCount', 1)),
            'next_billing_date' => $this->date($node['nextBillingDate'] ?? null),
            'currency' => (string) data_get($node, 'currencyCode', 'USD'),
            'amount' => $price !== null ? round((float) $price, 2) : null,
            'shopify_customer_gid' => (string) data_get($node, 'customer.id', '') ?: null,
            'customer_email' => (string) data_get($node, 'customer.email', '') ?: null,
            'customer_name' => trim((string) data_get($node, 'customer.firstName', '').' '
                .(string) data_get($node, 'customer.lastName', '')) ?: null,
            'lines' => $lines !== [] ? $lines : null,
        ]);
    }

    /**
     * The single write path. shop_id is taken from the EXPLICIT $shop — never the
     * bound tenant — so webhook jobs and admin reads behave identically.
     *
     * @param  array<string, mixed>  $attributes  must contain shopify_gid
     */
    private function upsert(Shop $shop, array $attributes): SubscriptionContract
    {
        $gid = (string) $attributes['shopify_gid'];
        unset($attributes['shopify_gid']);

        // Drop nulls so a sparse webhook payload never blanks fields a richer
        // GraphQL sync already filled (e.g. lines, customer_email).
        $attributes = array_filter($attributes, static fn ($v): bool => $v !== null);
        $attributes['synced_at'] = now();

        $contract = SubscriptionContract::query()
            ->where('shop_id', (int) $shop->getKey())
            ->where('shopify_gid', $gid)
            ->first();

        if ($contract === null) {
            $contract = new SubscriptionContract();
            $contract->forceFill(['shop_id' => (int) $shop->getKey(), 'shopify_gid' => $gid]);
        }

        $contract->forceFill($attributes)->save();

        return $contract;
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $payload */
    private function customerGid(array $payload): ?string
    {
        $id = data_get($payload, 'customer_id') ?? data_get($payload, 'customer.id');

        if ($id === null || $id === '') {
            return null;
        }

        $id = (string) $id;

        return str_starts_with($id, 'gid://') ? $id : 'gid://shopify/Customer/'.$id;
    }
}
