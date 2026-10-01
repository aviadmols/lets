<?php

namespace App\Domain\Analytics\Upsells;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Delta;
use App\Domain\Analytics\Support\Sql;
use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Models\AccountOffer;
use App\Models\ActivityEvent;
use App\Models\PaymentLedger;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Support\Facades\DB;

/**
 * Upsells › Added and › Sold — the numbers, by CHANNEL, as LETS records them.
 *
 * CHANNELS (docs/analytics/data-map.md §5):
 *   checkout     — the after-purchase offer: the thank-you page (PayPlus token
 *                  charge) and Shopify's post-purchase step. Source:
 *                  upsell_offer_events (impression → accepted → charge_*).
 *   account_area — offers the customer takes inside their own account area.
 *                  Source: the account_offer_accepted Timeline row (+ the
 *                  payment_ledger row of a one-time add-on bought now).
 *   Admin-added extras and campaign-attributed upsells are NOT tracked — no
 *   event records them; the screen says so instead of drawing a zero.
 *
 * ADDED = what the customer said yes to, valued when they said it:
 *   checkout     — an `accepted` event, at the offer's CURRENT price (offer
 *                  prices are not versioned — the screen notes it);
 *   account_area — an acceptance that ADDS (a one-time add-on, or a new
 *                  subscription taken beside the old one), at its quoted amount.
 *                  A REPLACE (switch) is a swap, not an upsell, and is left out.
 * SOLD = money that actually moved, net of refunds:
 *   checkout     — `charge_succeeded` events (Shopify's post-purchase step is
 *                  charged by Shopify and never confirmed back: added, not sold);
 *   account_area — settled payment_ledger rows of context `account_offer`.
 */
final class UpsellsQuery
{
    // === CONSTANTS ===
    public const CHANNEL_ALL = 'all';

    public const CHANNEL_CHECKOUT = 'checkout';

    public const CHANNEL_ACCOUNT = 'account_area';

    /** Tracked channels, in legend order. */
    public const CHANNELS = [self::CHANNEL_CHECKOUT, self::CHANNEL_ACCOUNT];

    /** Toggle values (first = default). */
    public const CHANNEL_OPTIONS = [self::CHANNEL_ALL, self::CHANNEL_CHECKOUT, self::CHANNEL_ACCOUNT];

    /** Channels the spec names that LETS does not record. */
    public const UNTRACKED_CHANNELS = ['admin', 'campaign'];

    public const SETTLED = [PaymentLedger::STATUS_SUCCEEDED, PaymentLedger::STATUS_REFUNDED];

    public const MODE_REPLACE = 'replace';

    public const CACHE_ADDED = 'upsells.added';

    public const CACHE_SOLD = 'upsells.sold';

    /** Chart ids. */
    public const CHART_ADDED = 'upsells_added';

    public const CHART_SOLD = 'upsells_sold';

    public function __construct(private readonly Context $context, private readonly string $channel = self::CHANNEL_ALL) {}

    public static function grain(Context $context, string $chartId): Granularity
    {
        return $context->grain($chartId, Granularity::WEEKLY);
    }

    private function wants(string $channel): bool
    {
        return $this->channel === self::CHANNEL_ALL || $this->channel === $channel;
    }

    // === Added ===

    /**
     * @return array{
     *   items: int, revenue: float, orders: int, sold_to_date: float,
     *   by_day: array<string, array<string, float>>,
     *   items_table: list<array{channel: string, key: string, title: ?string, price: float, count: int, revenue: float}>,
     *   profiles: list<array{channel: string, id: int, name: ?string, shown: ?int, accepted: int, revenue: float}>
     * }
     */
    public function added(Period $window): array
    {
        return AnalyticsCache::remember(self::CACHE_ADDED, $this->context->period, $this->context->filters, function () use ($window): array {
            $out = ['items' => 0, 'revenue' => 0.0, 'orders' => 0, 'sold_to_date' => 0.0, 'by_day' => [], 'items_table' => [], 'profiles' => []];
            if ($this->wants(self::CHANNEL_CHECKOUT)) {
                $this->mergeAdded($out, $this->checkoutAdded($window));
            }
            if ($this->wants(self::CHANNEL_ACCOUNT)) {
                $this->mergeAdded($out, $this->accountAdded($window));
            }
            $out['revenue'] = round($out['revenue'], 2);
            $out['sold_to_date'] = round($out['sold_to_date'], 2);

            return $out;
        }, $this->channel.'|'.$window->key());
    }

    private function mergeAdded(array &$out, array $part): void
    {
        $out['items'] += $part['items'];
        $out['revenue'] += $part['revenue'];
        $out['orders'] += $part['orders'];
        $out['sold_to_date'] += $part['sold_to_date'];
        $out['by_day'] = array_merge($out['by_day'], $part['by_day']);
        array_push($out['items_table'], ...$part['items_table']);
        array_push($out['profiles'], ...$part['profiles']);
    }

    /** The price an offer charges, in SQL (UpsellFlowOffer::discountedPrice() mirrored). */
    public static function offerPrice(string $o = 'o'): string
    {
        return "(CASE WHEN {$o}.product_selection_mode = '".UpsellFlowOffer::PRODUCT_BUNDLE."' THEN COALESCE({$o}.bundle_price, 0)"
            ." WHEN {$o}.discount_type = '".UpsellFlowOffer::DISCOUNT_PERCENT."' THEN"
            ." (CASE WHEN {$o}.discount_value >= 100 THEN 0 WHEN {$o}.discount_value <= 0 THEN {$o}.base_price"
            ." ELSE {$o}.base_price * (100.0 - {$o}.discount_value) / 100.0 END)"
            ." WHEN {$o}.discount_type = '".UpsellFlowOffer::DISCOUNT_FIXED."' THEN"
            ." (CASE WHEN {$o}.base_price - {$o}.discount_value < 0 THEN 0 ELSE {$o}.base_price - {$o}.discount_value END)"
            ." ELSE {$o}.base_price END)";
    }

    private function events(): \Illuminate\Database\Eloquent\Builder
    {
        return UpsellOfferEvent::query()->join('upsell_flow_offers as o', function ($join): void {
            $join->on('o.id', '=', 'upsell_offer_events.offer_id')
                ->on('o.shop_id', '=', 'upsell_offer_events.shop_id');
        });
    }

    private function checkoutAdded(Period $w): array
    {
        $accepted = OfferEventType::ACCEPTED->value;
        $impression = OfferEventType::IMPRESSION->value;
        $price = self::offerPrice();
        $day = Sql::day('upsell_offer_events.occurred_at');
        $window = [$w->start(), $w->end()];

        $byOffer = $this->events()
            ->where('upsell_offer_events.event_type', $accepted)
            ->whereBetween('upsell_offer_events.occurred_at', $window)
            ->selectRaw("{$day} as d, upsell_offer_events.offer_id as offer_id, MAX(o.offer_title) as title, MAX({$price}) as price, COUNT(*) as n, COALESCE(SUM({$price}), 0) as revenue")
            ->groupByRaw("{$day}, upsell_offer_events.offer_id")
            ->toBase()->get();

        $orders = (int) UpsellOfferEvent::query()
            ->where('event_type', $accepted)
            ->whereBetween('occurred_at', $window)
            ->selectRaw("COUNT(DISTINCT COALESCE(parent_order_id, customer_ref, 'event:' || id)) as n")
            ->toBase()->value('n');

        $profiles = $this->events()
            ->whereIn('upsell_offer_events.event_type', [$accepted, $impression])
            ->whereBetween('upsell_offer_events.occurred_at', $window)
            ->selectRaw(implode(', ', [
                'upsell_offer_events.flow_id as flow_id',
                "SUM(CASE WHEN upsell_offer_events.event_type = '{$impression}' THEN 1 ELSE 0 END) as shown",
                "SUM(CASE WHEN upsell_offer_events.event_type = '{$accepted}' THEN 1 ELSE 0 END) as accepted",
                "COALESCE(SUM(CASE WHEN upsell_offer_events.event_type = '{$accepted}' THEN {$price} ELSE 0 END), 0) as revenue",
            ]))
            ->groupBy('upsell_offer_events.flow_id')
            ->toBase()->get();
        $names = UpsellFlow::query()->whereIn('id', $profiles->pluck('flow_id')->all())->pluck('name', 'id');

        // Sold to date: charges that landed (at any time so far) for an offer
        // accepted inside the window — same offer, same parent order.
        $sold = UpsellOfferEvent::query()
            ->leftJoin('payment_ledger as pl', function ($join): void {
                $join->on('pl.id', '=', 'upsell_offer_events.payment_ledger_id')
                    ->on('pl.shop_id', '=', 'upsell_offer_events.shop_id');
            })
            ->where('upsell_offer_events.event_type', OfferEventType::CHARGE_SUCCEEDED->value)
            ->whereExists(function ($q) use ($accepted, $window): void {
                $q->selectRaw('1')->from('upsell_offer_events as a')
                    ->whereColumn('a.shop_id', 'upsell_offer_events.shop_id')
                    ->whereColumn('a.offer_id', 'upsell_offer_events.offer_id')
                    ->whereColumn('a.parent_order_id', 'upsell_offer_events.parent_order_id')
                    ->where('a.event_type', $accepted)
                    ->whereBetween('a.occurred_at', $window);
            })
            ->selectRaw('COALESCE(SUM(COALESCE(upsell_offer_events.revenue_amount, 0) - COALESCE(pl.refunded_amount, 0)), 0) as v')
            ->toBase()->value('v');

        $out = ['items' => 0, 'revenue' => 0.0, 'orders' => $orders, 'sold_to_date' => (float) $sold, 'by_day' => [], 'items_table' => [], 'profiles' => []];
        $items = [];
        foreach ($byOffer as $r) {
            $out['items'] += (int) $r->n;
            $out['revenue'] += (float) $r->revenue;
            $out['by_day'][self::CHANNEL_CHECKOUT][(string) $r->d] = ($out['by_day'][self::CHANNEL_CHECKOUT][(string) $r->d] ?? 0.0) + (float) $r->revenue;
            $key = 'offer:'.$r->offer_id;
            $items[$key] ??= ['channel' => self::CHANNEL_CHECKOUT, 'key' => $key, 'title' => $r->title !== null ? (string) $r->title : null, 'price' => round((float) $r->price, 2), 'count' => 0, 'revenue' => 0.0];
            $items[$key]['count'] += (int) $r->n;
            $items[$key]['revenue'] = round($items[$key]['revenue'] + (float) $r->revenue, 2);
        }
        $out['items_table'] = array_values($items);
        foreach ($profiles as $p) {
            $out['profiles'][] = [
                'channel' => self::CHANNEL_CHECKOUT,
                'id' => (int) $p->flow_id,
                'name' => isset($names[$p->flow_id]) ? (string) $names[$p->flow_id] : null,
                'shown' => (int) $p->shown,
                'accepted' => (int) $p->accepted,
                'revenue' => round((float) $p->revenue, 2),
            ];
        }

        return $out;
    }

    /** One row per account-area acceptance that ADDS: d, oid, item, qty, value, plan, ledger. */
    private function acceptances(Period $w): \Illuminate\Database\Query\Builder
    {
        $j = static fn (string $key): string => Sql::jsonText('activity_events.details', $key);

        return ActivityEvent::query()
            ->join('installment_plans', function ($join): void {
                $join->on('installment_plans.id', '=', 'activity_events.plan_id')
                    ->on('installment_plans.shop_id', '=', 'activity_events.shop_id');
            })
            ->where('activity_events.kind', Timeline::KIND_ACCOUNT_OFFER_ACCEPTED)
            ->whereBetween('activity_events.created_at', [$w->start(), $w->end()])
            // A switch writes the row on BOTH plans; count the new plan's copy only.
            ->whereRaw("({$j('new_plan')} IS NULL OR installment_plans.public_id = {$j('new_plan')})")
            ->whereRaw("({$j('mode')} IS NULL OR {$j('mode')} <> ?)", [self::MODE_REPLACE])
            ->selectRaw(implode(', ', [
                Sql::day('activity_events.created_at').' as d',
                "CAST({$j('offer_id')} AS TEXT) as oid",
                "COALESCE(NULLIF(CAST({$j('product')} AS TEXT), ''), NULLIF(CAST({$j('offer_name')} AS TEXT), '')) as item",
                "COALESCE(CAST({$j('quantity')} AS INTEGER), 1) as qty",
                "COALESCE(CAST({$j('first_charge_amount')} AS NUMERIC), CAST({$j('amount')} AS NUMERIC), 0) as value",
                'installment_plans.id as plan',
                'activity_events.shop_id as shop',
                "CAST({$j('ledger_id')} AS TEXT) as ledger",
            ]))
            ->toBase();
    }

    private function accountAdded(Period $w): array
    {
        $rows = DB::query()->fromSub($this->acceptances($w), 'a')
            ->selectRaw('d, oid, item, COUNT(*) as n, COALESCE(SUM(qty), 0) as qty, COALESCE(SUM(value), 0) as revenue')
            ->groupBy('d', 'oid', 'item')
            ->get();

        $orders = (int) DB::query()->fromSub($this->acceptances($w), 'a')->selectRaw('COUNT(DISTINCT plan) as n')->value('n');

        $sold = (float) DB::query()->fromSub($this->acceptances($w), 'a')
            // The acceptance rows are tenant-scoped; the ledger is pinned to their shop.
            ->join('payment_ledger as pl', function ($join): void {
                $join->on(DB::raw('CAST(pl.id AS TEXT)'), '=', 'a.ledger')->on('pl.shop_id', '=', 'a.shop');
            })
            ->whereIn('pl.status', self::SETTLED)
            ->where('pl.charge_context', PaymentLedger::CONTEXT_ACCOUNT_OFFER)
            ->selectRaw('COALESCE(SUM(COALESCE(pl.amount, 0) - COALESCE(pl.refunded_amount, 0)), 0) as v')
            ->value('v');

        $out = ['items' => 0, 'revenue' => 0.0, 'orders' => $orders, 'sold_to_date' => $sold, 'by_day' => [], 'items_table' => [], 'profiles' => []];
        $items = [];
        $profiles = [];
        foreach ($rows as $r) {
            $out['items'] += (int) $r->qty;
            $out['revenue'] += (float) $r->revenue;
            $out['by_day'][self::CHANNEL_ACCOUNT][(string) $r->d] = ($out['by_day'][self::CHANNEL_ACCOUNT][(string) $r->d] ?? 0.0) + (float) $r->revenue;
            $key = 'account:'.$r->oid.':'.$r->item;
            $items[$key] ??= ['channel' => self::CHANNEL_ACCOUNT, 'key' => $key, 'title' => $r->item !== null ? (string) $r->item : null, 'price' => 0.0, 'count' => 0, 'revenue' => 0.0];
            $items[$key]['count'] += (int) $r->qty;
            $items[$key]['revenue'] = round($items[$key]['revenue'] + (float) $r->revenue, 2);
            $profiles[(string) $r->oid] = [
                'accepted' => ($profiles[(string) $r->oid]['accepted'] ?? 0) + (int) $r->n,
                'revenue' => round(($profiles[(string) $r->oid]['revenue'] ?? 0.0) + (float) $r->revenue, 2),
            ];
        }
        foreach ($items as &$item) {
            $item['price'] = $item['count'] > 0 ? round($item['revenue'] / $item['count'], 2) : 0.0;
        }
        unset($item);
        $out['items_table'] = array_values($items);

        $names = AccountOffer::query()->whereIn('id', array_filter(array_map('intval', array_keys($profiles))))->pluck('name', 'id');
        foreach ($profiles as $id => $p) {
            $out['profiles'][] = [
                'channel' => self::CHANNEL_ACCOUNT,
                'id' => (int) $id,
                'name' => isset($names[(int) $id]) ? (string) $names[(int) $id] : null,
                'shown' => null, // the account area records no impressions
                'accepted' => $p['accepted'],
                'revenue' => $p['revenue'],
            ];
        }

        return $out;
    }

    // === Sold ===

    /**
     * @return array{
     *   items: int, revenue: float, orders: int, collected: float, contribution: ?float,
     *   by_day: array<string, array<string, float>>,
     *   items_table: list<array{channel: string, key: string, title: ?string, count: int, revenue: float}>
     * }
     */
    public function sold(Period $window): array
    {
        return AnalyticsCache::remember(self::CACHE_SOLD, $this->context->period, $this->context->filters, function () use ($window): array {
            $out = ['items' => 0, 'revenue' => 0.0, 'orders' => 0, 'by_day' => [], 'items_table' => []];
            if ($this->wants(self::CHANNEL_CHECKOUT)) {
                $this->mergeSold($out, $this->checkoutSold($window));
            }
            if ($this->wants(self::CHANNEL_ACCOUNT)) {
                $this->mergeSold($out, $this->accountSold($window));
            }
            $out['revenue'] = round($out['revenue'], 2);

            $collected = (float) PaymentLedger::query()
                ->whereIn('status', self::SETTLED)
                ->whereBetween('created_at', [$window->start(), $window->end()])
                ->selectRaw('COALESCE(SUM(COALESCE(amount, 0) - COALESCE(refunded_amount, 0)), 0) as v')
                ->toBase()->value('v');
            $out['collected'] = round($collected, 2);
            $out['contribution'] = $collected > 0 ? Delta::share($out['revenue'], $collected) : null;

            return $out;
        }, $this->channel.'|'.$window->key());
    }

    private function mergeSold(array &$out, array $part): void
    {
        $out['items'] += $part['items'];
        $out['revenue'] += $part['revenue'];
        $out['orders'] += $part['orders'];
        $out['by_day'] = array_merge($out['by_day'], $part['by_day']);
        array_push($out['items_table'], ...$part['items_table']);
    }

    private function checkoutSold(Period $w): array
    {
        $day = Sql::day('upsell_offer_events.occurred_at');
        $net = '(COALESCE(upsell_offer_events.revenue_amount, 0) - COALESCE(pl.refunded_amount, 0))';
        $base = fn () => $this->events()
            ->leftJoin('payment_ledger as pl', function ($join): void {
                $join->on('pl.id', '=', 'upsell_offer_events.payment_ledger_id')
                    ->on('pl.shop_id', '=', 'upsell_offer_events.shop_id');
            })
            ->where('upsell_offer_events.event_type', OfferEventType::CHARGE_SUCCEEDED->value)
            ->whereBetween('upsell_offer_events.occurred_at', [$w->start(), $w->end()]);

        $rows = $base()
            ->selectRaw("{$day} as d, upsell_offer_events.offer_id as offer_id, MAX(o.offer_title) as title, COUNT(*) as n, COALESCE(SUM({$net}), 0) as revenue")
            ->groupByRaw("{$day}, upsell_offer_events.offer_id")
            ->toBase()->get();
        $orders = (int) $base()
            ->selectRaw("COUNT(DISTINCT COALESCE(upsell_offer_events.parent_order_id, 'event:' || upsell_offer_events.id)) as n")
            ->toBase()->value('n');

        $out = ['items' => 0, 'revenue' => 0.0, 'orders' => $orders, 'by_day' => [], 'items_table' => []];
        $items = [];
        foreach ($rows as $r) {
            $out['items'] += (int) $r->n;
            $out['revenue'] += (float) $r->revenue;
            $out['by_day'][self::CHANNEL_CHECKOUT][(string) $r->d] = ($out['by_day'][self::CHANNEL_CHECKOUT][(string) $r->d] ?? 0.0) + (float) $r->revenue;
            $key = 'offer:'.$r->offer_id;
            $items[$key] ??= ['channel' => self::CHANNEL_CHECKOUT, 'key' => $key, 'title' => $r->title !== null ? (string) $r->title : null, 'count' => 0, 'revenue' => 0.0];
            $items[$key]['count'] += (int) $r->n;
            $items[$key]['revenue'] = round($items[$key]['revenue'] + (float) $r->revenue, 2);
        }
        $out['items_table'] = array_values($items);

        return $out;
    }

    private function accountSold(Period $w): array
    {
        $day = Sql::day('payment_ledger.created_at');
        $j = static fn (string $key): string => Sql::jsonText('ae.details', $key);
        $net = '(COALESCE(payment_ledger.amount, 0) - COALESCE(payment_ledger.refunded_amount, 0))';

        // The ledger is the money truth; the acceptance row only names the item.
        $rows = PaymentLedger::query()
            ->leftJoin('activity_events as ae', function ($join) use ($j): void {
                $join->on('ae.shop_id', '=', 'payment_ledger.shop_id')
                    ->where('ae.kind', Timeline::KIND_ACCOUNT_OFFER_ACCEPTED)
                    ->whereRaw("CAST({$j('ledger_id')} AS TEXT) = CAST(payment_ledger.id AS TEXT)");
            })
            ->where('payment_ledger.charge_context', PaymentLedger::CONTEXT_ACCOUNT_OFFER)
            ->whereIn('payment_ledger.status', self::SETTLED)
            ->whereBetween('payment_ledger.created_at', [$w->start(), $w->end()])
            ->selectRaw(implode(', ', [
                "{$day} as d",
                "COALESCE(NULLIF(CAST({$j('product')} AS TEXT), ''), NULLIF(CAST({$j('offer_name')} AS TEXT), '')) as item",
                'COUNT(*) as n',
                "COALESCE(SUM({$net}), 0) as revenue",
            ]))
            ->groupByRaw("{$day}, COALESCE(NULLIF(CAST({$j('product')} AS TEXT), ''), NULLIF(CAST({$j('offer_name')} AS TEXT), ''))")
            ->toBase()->get();

        $out = ['items' => 0, 'revenue' => 0.0, 'orders' => 0, 'by_day' => [], 'items_table' => []];
        $items = [];
        foreach ($rows as $r) {
            $out['items'] += (int) $r->n;
            $out['orders'] += (int) $r->n; // one add-on purchase = one charge = one order
            $out['revenue'] += (float) $r->revenue;
            $out['by_day'][self::CHANNEL_ACCOUNT][(string) $r->d] = ($out['by_day'][self::CHANNEL_ACCOUNT][(string) $r->d] ?? 0.0) + (float) $r->revenue;
            $key = 'account:'.($r->item ?? '');
            $items[$key] ??= ['channel' => self::CHANNEL_ACCOUNT, 'key' => $key, 'title' => $r->item !== null ? (string) $r->item : null, 'count' => 0, 'revenue' => 0.0];
            $items[$key]['count'] += (int) $r->n;
            $items[$key]['revenue'] = round($items[$key]['revenue'] + (float) $r->revenue, 2);
        }
        $out['items_table'] = array_values($items);

        return $out;
    }
}
