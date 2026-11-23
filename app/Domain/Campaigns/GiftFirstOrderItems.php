<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\WooCommerce\WooClientFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What ELSE a brand-new subscriber bought in the order they subscribed through.
 *
 * A member still in their first cycle has one order — the checkout that started the
 * subscription. When it carried other products beside the subscription, the merchant
 * ships them in the same package as the gift, so the courier sheet lists them: every
 * product's name and quantity, in ONE cell.
 *
 * Only for the first cycle. From the second cycle on, that order was shipped long ago
 * and reprinting it would put old goods on today's package.
 *
 * The subscription's own line is left out — it is already the "subscription" column,
 * and it is not an extra product. Matched on the plan's product id; a plan with no
 * product id falls back to its title.
 *
 * Reads only, and never throws: a store that cannot answer leaves the cell empty, not
 * the export failed.
 */
final class GiftFirstOrderItems
{
    // === CONSTANTS ===
    /**
     * At most this many succeeded charges is still the first cycle. The checkout's own
     * charge is recorded as a succeeded payment (PlanActivationService, sequence 0), so a
     * member who has only paid at checkout counts exactly one.
     */
    public const FIRST_CYCLE_MAX = 1;

    /** Between one product and the next inside the one cell. */
    public const ITEM_SEPARATOR = ' | ';

    /** Order lines one Shopify read asks for — far beyond any real checkout. */
    private const SHOPIFY_LINE_LIMIT = 100;

    /**
     * The cell for each row: the first order's other products, or '' for a row that is
     * past its first cycle, has no order, or bought nothing else.
     *
     * `$reads` is what GiftAddressResolver::readInBulk() already brought back for the
     * same rows. On WooCommerce its origin orders are the very orders this needs — the
     * ones the address and courier note were read from — so a successful bulk read
     * costs this NO extra request. Only when it read nothing (a failed bulk read, a
     * Shopify store) does this read the orders itself.
     *
     * @param  array<int|string, array<string, mixed>>  $rows  GiftEligibility::qualifying() rows
     * @param  array{plans?: array<int, InstallmentPlan>, orders?: array<int, array<string, mixed>>}|null  $reads
     * @return array<int|string, string> the same keys
     */
    public function forMany(Shop $shop, array $rows, ?array $reads = null): array
    {
        $cells = array_map(static fn (): string => '', $rows);

        $planIds = [];
        foreach ($rows as $key => $row) {
            if (self::isFirstCycle($row)) {
                $planIds[$key] = (int) $row['source_id'];
            }
        }

        if ($planIds === []) {
            return $cells;
        }

        // Tenant-scoped: a plan id from another shop resolves to nothing.
        $plans = InstallmentPlan::query()->whereKey(array_values($planIds))->get()->keyBy('id');

        $orderIds = [];
        foreach ($planIds as $planId) {
            $orderId = trim((string) $plans->get($planId)?->externalOrderId());
            if ($orderId !== '') {
                $orderIds[$planId] = $orderId;
            }
        }

        $alreadyRead = ($reads['plans'] ?? []) !== [];

        $lines = match (true) {
            $shop->platform !== Shop::PLATFORM_WOOCOMMERCE => $this->shopifyLines($shop, $orderIds),
            $alreadyRead => self::wooLinesOf((array) ($reads['orders'] ?? [])),
            default => $this->wooLines($shop, $orderIds),
        };

        foreach ($planIds as $key => $planId) {
            $plan = $plans->get($planId);
            $orderId = $orderIds[$planId] ?? null;

            if ($plan !== null && $orderId !== null && isset($lines[$orderId])) {
                $cells[$key] = $this->cell($plan, $lines[$orderId]);
            }
        }

        return $cells;
    }

    /**
     * A PayPlus-rail subscriber who has paid no more than the checkout. Shopify-Payments
     * contracts keep no origin order on our side, so they have nothing to list.
     *
     * @param  array<string, mixed>  $row
     */
    public static function isFirstCycle(array $row): bool
    {
        return ($row['source_type'] ?? null) === GiftRecipient::SOURCE_PLAN
            && (int) ($row['cycles'] ?? 0) <= self::FIRST_CYCLE_MAX;
    }

    /**
     * "ספר א × 2 | ספר ב × 1" — the subscription's own line left out.
     *
     * @param  list<array{name: string, quantity: int, product_ids: list<string>}>  $lines
     */
    private function cell(InstallmentPlan $plan, array $lines): string
    {
        $productId = trim((string) $plan->externalProductId());
        $title = trim((string) $plan->productTitle());

        $items = [];
        foreach ($lines as $line) {
            $isSubscription = $productId !== ''
                ? in_array($productId, $line['product_ids'], true)
                : ($title !== '' && $line['name'] === $title);

            if ($isSubscription || $line['name'] === '' || $line['quantity'] <= 0) {
                continue;
            }

            $items[] = (string) __('gifts.export.first_order_item', ['name' => $line['name'], 'qty' => $line['quantity']]);
        }

        return implode(self::ITEM_SEPARATOR, $items);
    }

    /**
     * WooCommerce: the orders a hundred to a request; one at a time only when the bulk
     * read fails.
     *
     * @param  array<int, string>  $orderIds  plan id → order id
     * @return array<string, list<array{name: string, quantity: int, product_ids: list<string>}>> order id → lines
     */
    private function wooLines(Shop $shop, array $orderIds): array
    {
        if ($orderIds === [] || ! $shop->hasWooConnection()) {
            return [];
        }

        $client = WooClientFactory::for($shop);

        try {
            $orders = $client->fetchOrdersByIds(array_values($orderIds));
        } catch (\Throwable $e) {
            Log::warning('campaigns.gift.first_order_bulk_read_failed', [
                'shop_id' => $shop->getKey(),
                'orders' => count($orderIds),
                'error' => $e->getMessage(),
            ]);

            $orders = [];
            foreach (array_unique($orderIds) as $orderId) {
                try {
                    $order = $client->fetchOrder($orderId);
                    if ($order !== null) {
                        $orders[(int) $orderId] = $order;
                    }
                } catch (\Throwable) {
                    // That one person's cell stays empty; the rest of the sheet goes on.
                }
            }
        }

        return self::wooLinesOf($orders);
    }

    /**
     * WooCommerce order payloads → their product lines.
     *
     * @param  array<int|string, array<string, mixed>>  $orders  keyed by order id
     * @return array<string, list<array{name: string, quantity: int, product_ids: list<string>}>>
     */
    private static function wooLinesOf(array $orders): array
    {
        $lines = [];
        foreach ($orders as $id => $order) {
            $lines[(string) $id] = array_map(static fn ($item): array => [
                'name' => trim((string) data_get($item, 'name', '')),
                'quantity' => (int) data_get($item, 'quantity', 0),
                // A variable product's line names both: the parent and the variation.
                'product_ids' => array_values(array_filter(array_map(
                    static fn ($id): string => (string) $id,
                    [data_get($item, 'product_id'), data_get($item, 'variation_id')],
                ), static fn (string $id): bool => $id !== '' && $id !== '0')),
            ], array_values(array_filter((array) data_get($order, 'line_items', []), 'is_array')));
        }

        return $lines;
    }

    /**
     * Shopify (the PayPlus rail on a Shopify store): one read per order. First-cycle
     * members are a small share of any list, so this is a handful of reads, not hundreds.
     *
     * @param  array<int, string>  $orderIds  plan id → order id
     * @return array<string, list<array{name: string, quantity: int, product_ids: list<string>}>> order id → lines
     */
    private function shopifyLines(Shop $shop, array $orderIds): array
    {
        if ($orderIds === [] || ! $shop->hasShopifyConnection()) {
            return [];
        }

        $client = ShopifyClientFactory::for($shop);
        $lines = [];

        foreach (array_unique($orderIds) as $orderId) {
            $gid = str_starts_with($orderId, 'gid://') ? $orderId : 'gid://shopify/Order/'.$orderId;

            try {
                $body = $client->graphql(
                    'query giftFirstOrder($id: ID!) { order(id: $id) { lineItems(first: '.self::SHOPIFY_LINE_LIMIT.') { nodes { name quantity product { id } variant { id } } } } }',
                    ['id' => $gid],
                );
            } catch (\Throwable) {
                continue;
            }

            $lines[$orderId] = array_map(static fn ($node): array => [
                'name' => trim((string) data_get($node, 'name', '')),
                'quantity' => (int) data_get($node, 'quantity', 0),
                // Stored on the plan as the bare number; Shopify answers with gids.
                'product_ids' => array_values(array_filter(array_map(
                    static fn ($gid): string => (string) Str::afterLast((string) $gid, '/'),
                    [data_get($node, 'product.id'), data_get($node, 'variant.id')],
                ), static fn (string $id): bool => $id !== '')),
            ], array_values(array_filter((array) data_get($body, 'data.order.lineItems.nodes', []), 'is_array')));
        }

        return $lines;
    }
}
