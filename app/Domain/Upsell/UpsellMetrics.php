<?php

namespace App\Domain\Upsell;

use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Support\Tenant;
use Illuminate\Support\Carbon;

/**
 * Read-only, tenant-scoped upsell analytics. The numbers behind the admin
 * Post-Purchase Offers page — KPI cards (Overview) + the per-flow funnel
 * (Performance tab). admin-design-system RENDERS this; it never queries
 * upsell_offer_events directly.
 *
 * THE DATA CONTRACT (method → shape):
 *   overview(?from,?to): array{
 *       impressions:int, accepted:int, declined:int,
 *       charge_succeeded:int, charge_failed:int,
 *       conversion_rate:float,          // accepted / impressions
 *       charge_success_rate:float,      // charge_succeeded / accepted
 *       total_revenue:float, currency:string,
 *       aov_uplift:float                // total_revenue / charge_succeeded
 *   }
 *   funnelForFlow(int $flowId, ?from,?to): same shape, scoped to one flow.
 *   perFlowFunnels(?from,?to): list<array{flow_id:int, ...overview shape...}>.
 *   revenueByDay(?from,?to): list<array{date:string, revenue:float, conversions:int}>.
 *
 * Every query runs under the BelongsToShop global scope (Tenant must be bound),
 * so a shop only ever sees its own funnel — proven by the isolation test.
 */
final class UpsellMetrics
{
    // === CONSTANTS ===
    private const CURRENCY_DEFAULT = 'ILS';

    /**
     * KPI overview across all flows for the shop (optionally windowed).
     *
     * @return array<string, mixed>
     */
    public function overview(?Carbon $from = null, ?Carbon $to = null): array
    {
        return $this->funnel($this->baseQuery($from, $to));
    }

    /**
     * Funnel for one flow.
     *
     * @return array<string, mixed>
     */
    public function funnelForFlow(int $flowId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $row = $this->funnel($this->baseQuery($from, $to)->where('flow_id', $flowId));
        $row['flow_id'] = $flowId;

        return $row;
    }

    /**
     * One funnel row per flow that has any events in the window.
     *
     * @return list<array<string, mixed>>
     */
    public function perFlowFunnels(?Carbon $from = null, ?Carbon $to = null): array
    {
        $flowIds = $this->baseQuery($from, $to)
            ->distinct()
            ->pluck('flow_id')
            ->all();

        $out = [];
        foreach ($flowIds as $flowId) {
            $out[] = $this->funnelForFlow((int) $flowId, $from, $to);
        }

        return $out;
    }

    /**
     * Revenue + conversions per calendar day (for the Performance sparkline).
     *
     * @return list<array{date: string, revenue: float, conversions: int}>
     */
    public function revenueByDay(?Carbon $from = null, ?Carbon $to = null): array
    {
        return $this->baseQuery($from, $to)
            ->where('event_type', OfferEventType::CHARGE_SUCCEEDED->value)
            ->selectRaw('date(occurred_at) as d, sum(revenue_amount) as revenue, count(*) as conversions')
            ->groupByRaw('date(occurred_at)')
            ->orderByRaw('date(occurred_at)')
            ->get()
            ->map(fn ($r): array => [
                'date' => (string) $r->d,
                'revenue' => round((float) $r->revenue, 2),
                'conversions' => (int) $r->conversions,
            ])
            ->all();
    }

    // === Internals ===

    /**
     * Collapse a scoped event query into the KPI shape. One grouped query gives
     * the per-type counts; revenue is summed off the succeeded rows.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return array<string, mixed>
     */
    private function funnel($query): array
    {
        // Run on the base (un-cast) query so event_type comes back as a raw
        // string we can index by — the Eloquent enum cast would turn the grouped
        // key into an object that can't be a collection key.
        $rows = (clone $query)
            ->toBase()
            ->selectRaw('event_type, count(*) as c, sum(revenue_amount) as revenue')
            ->groupBy('event_type')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->event_type] = $row;
        }

        $get = fn (OfferEventType $t): int => (int) ($counts[$t->value]->c ?? 0);

        $impressions = $get(OfferEventType::IMPRESSION);
        $accepted = $get(OfferEventType::ACCEPTED);
        $declined = $get(OfferEventType::DECLINED);
        $chargeSucceeded = $get(OfferEventType::CHARGE_SUCCEEDED);
        $chargeFailed = $get(OfferEventType::CHARGE_FAILED);

        $revenue = round((float) ($counts[OfferEventType::CHARGE_SUCCEEDED->value]->revenue ?? 0), 2);

        return [
            'impressions' => $impressions,
            'accepted' => $accepted,
            'declined' => $declined,
            'charge_succeeded' => $chargeSucceeded,
            'charge_failed' => $chargeFailed,
            'conversion_rate' => $this->ratio($accepted, $impressions),
            'charge_success_rate' => $this->ratio($chargeSucceeded, $accepted),
            'total_revenue' => $revenue,
            'currency' => self::CURRENCY_DEFAULT,
            // Average revenue per converted upsell — the "AOV uplift" per accepted
            // offer. Zero when nothing converted (never divide by zero).
            'aov_uplift' => $chargeSucceeded > 0 ? round($revenue / $chargeSucceeded, 2) : 0.0,
        ];
    }

    /** Base tenant-scoped query, optionally windowed by occurred_at. */
    private function baseQuery(?Carbon $from, ?Carbon $to)
    {
        // Explicit shop_id for defence in depth on top of the global scope.
        $query = UpsellOfferEvent::query()->where('shop_id', Tenant::id());

        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('occurred_at', '<=', $to);
        }

        return $query;
    }

    /** Safe ratio in [0,1], rounded to 4dp; 0 when the denominator is 0. */
    private function ratio(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 4) : 0.0;
    }
}
