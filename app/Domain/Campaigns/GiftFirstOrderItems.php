<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
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

    /** How many of a customer's newest orders to look through for one with a subscription. */
    public const RECENT_ORDERS_PER_CUSTOMER = 20;

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
    public function forMany(Shop $shop, array $rows, ?array $reads = null, bool $firstCycleOnly = true): array
    {
        $cells = array_map(static fn (): string => '', $rows);

        // A gift list asks only about first-cycle members (their order ships with the gift);
        // the Active subscriptions sheet asks about every subscription bought through the site.
        $planIds = [];
        foreach ($rows as $key => $row) {
            $isPlan = ($row['source_type'] ?? null) === GiftRecipient::SOURCE_PLAN;
            if ($isPlan && (! $firstCycleOnly || self::isFirstCycle($row))) {
                $planIds[$key] = (int) $row['source_id'];
            }
        }

        if ($planIds === []) {
            return $cells;
        }

        // Tenant-scoped: a plan id from another shop resolves to nothing. An imported
        // member did not buy through the site — their "order" is the old system's.
        $allPlans = InstallmentPlan::query()->whereKey(array_values($planIds))->get()->keyBy('id');
        $plans = $allPlans->reject(static fn (InstallmentPlan $p): bool => data_get($p->meta, 'import') !== null);

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

        // The Active sheet, "every subscriber": a subscriber with NO opening order here (an
        // imported member) gets the books of their latest store order that had a subscription.
        if (! $firstCycleOnly && $shop->platform === Shop::PLATFORM_WOOCOMMERCE) {
            $orphans = [];
            foreach ($planIds as $key => $planId) {
                $plan = $allPlans->get($planId);
                if ($plan !== null && ! isset($orderIds[$planId])) {
                    $orphans[$key] = $plan;
                }
            }
            foreach ($this->latestSubscriptionOrderCells($shop, $orphans) as $key => $cell) {
                $cells[$key] = $cell;
            }
        }

        return $cells;
    }

    /**
     * For subscribers with no opening order on our side: the other products of the
     * customer's NEWEST store order that contained a subscription product — any date
     * (approved 2026-11-24). Orders without a subscription product are one-off purchases
     * and never count. The customer is found by store id, or by email for a guest or a
     * member imported from another system.
     *
     * @param  array<int|string, InstallmentPlan>  $plans  row key → plan
     * @return array<int|string, string> row key → cell (only rows that found one)
     */
    private function latestSubscriptionOrderCells(Shop $shop, array $plans): array
    {
        if ($plans === [] || ! $shop->hasWooConnection()) {
            return [];
        }

        $who = array_map(static fn (InstallmentPlan $p): array => [
            'customer' => ctype_digit(trim((string) $p->externalCustomerId())) ? (int) $p->externalCustomerId() : 0,
            'email' => (string) ($p->customer_email ?? ''),
        ], $plans);

        try {
            $ordersByRow = WooClientFactory::for($shop)->fetchRecentOrdersFor($who, self::RECENT_ORDERS_PER_CUSTOMER);
        } catch (\Throwable $e) {
            Log::warning('campaigns.gift.subscription_orders_read_failed', ['shop_id' => $shop->getKey(), 'customers' => count($who), 'error' => $e->getMessage()]);

            return [];
        }

        $subscriptionIds = $this->subscriptionProductIds();
        $cells = [];

        foreach ($ordersByRow as $key => $orders) {
            // Newest first: the first order holding a subscription line is the one.
            foreach (self::wooLinesOf(array_values($orders)) as $lines) {
                $hasSubscription = array_filter($lines, static fn (array $l): bool => array_intersect($l['product_ids'], $subscriptionIds) !== []) !== [];
                if (! $hasSubscription) {
                    continue;
                }

                $items = [];
                foreach ($lines as $line) {
                    if (array_intersect($line['product_ids'], $subscriptionIds) !== [] || $line['name'] === '' || $line['quantity'] <= 0) {
                        continue;
                    }
                    $items[] = (string) __('gifts.export.first_order_item', ['name' => $line['name'], 'qty' => $line['quantity']]);
                }
                $cells[$key] = implode(self::ITEM_SEPARATOR, $items);
                break;
            }
        }

        // One line per batch, so an empty column can be explained from the logs: did the
        // store not answer, have no orders for these people, or orders without a subscription?
        Log::info('campaigns.gift.subscription_orders_lookup', [
            'shop_id' => $shop->getKey(),
            'asked' => count($who),
            'failed' => count($who) - count($ordersByRow),
            'no_orders' => count(array_filter($ordersByRow, static fn (array $o): bool => $o === [])),
            'with_orders' => count(array_filter($ordersByRow, static fn (array $o): bool => $o !== [])),
            'with_subscription_order' => count($cells),
            'with_books' => count(array_filter($cells, static fn (string $c): bool => $c !== '')),
        ]);

        return $cells;
    }

    /**
     * The store's subscription products: everything a subscription in this shop renews,
     * plus every catalog product that carries a subscription plan.
     *
     * @return list<string> store product ids
     */
    private function subscriptionProductIds(): array
    {
        $fromPlans = InstallmentPlan::query()
            ->where('plan_kind', PlanKind::RECURRING->value)
            ->get(['id', 'shop_id', 'external_product_id', 'shopify_product_id'])
            ->map(static fn (InstallmentPlan $p): string => trim((string) $p->externalProductId()));

        $fromCatalog = Product::query()->whereHas('subscriptionPlans')->pluck('external_id')
            ->map(static fn ($id): string => trim((string) $id));

        return $fromPlans->merge($fromCatalog)->filter()->unique()->values()->all();
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
