<?php

namespace App\Domain\Analytics\Subscribers;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Delta;
use App\Domain\Analytics\Support\Sql;
use Illuminate\Support\Facades\DB;

/**
 * Subscribers › Revenue — the numbers (spec §1.4, data-map §1.4).
 *
 * TRACKED: subscription revenue, split into CHECKOUT (a plan's first realised
 * charge) and RECURRING (every later one + every Shopify billing attempt) —
 * see SubscriptionLedger. Orders placed, revenue (net of refunds) and AOV per
 * bucket, with the comparison window's totals for the KPI deltas.
 *
 * NOT TRACKED: overall store revenue, non-subscription revenue and the
 * subscriber vs non-subscriber split — LETS ingests no store orders outside
 * the plans it bills (needs an orders feed).
 *
 * SQL: one GROUP BY day × kind over the ledger and one GROUP BY day over the
 * Shopify attempts, from the earliest moment the period or its comparison
 * reads; Granularity::rollUp() folds days into buckets.
 */
final class RevenueQuery
{
    // === CONSTANTS ===
    public const CACHE_DAYS = 'revenue.days';

    public const CHART_ID = 'subscription_revenue';

    public const KINDS = [SubscriptionLedger::KIND_RECURRING, SubscriptionLedger::KIND_CHECKOUT];

    public function __construct(private readonly Context $context) {}

    public static function grain(Context $context): Granularity
    {
        return $context->grain(self::CHART_ID, Granularity::WEEKLY);
    }

    /**
     * @return array{
     *   totals: array<string, array{orders: int, revenue: float}>,
     *   previous: ?array<string, array{orders: int, revenue: float}>,
     *   series: array{labels: list<string>, orders: array<string, list<float>>, revenue: array<string, list<float>>},
     *   has_data: bool
     * }
     */
    public function get(): array
    {
        $period = $this->context->period;
        $compare = $period->comparison();
        $days = $this->days();
        $grain = self::grain($this->context);

        $series = ['labels' => array_column($grain->buckets($period), 'label'), 'orders' => [], 'revenue' => []];
        foreach (self::KINDS as $kind) {
            $series['orders'][$kind] = $grain->rollUp($period, array_map(static fn (array $d): int => $d['orders'], $days[$kind]));
            $series['revenue'][$kind] = array_map(static fn (float $v): float => round($v, 2),
                $grain->rollUp($period, array_map(static fn (array $d): float => $d['revenue'], $days[$kind])));
        }

        $totals = $this->totals($days, $period);

        return [
            'totals' => $totals,
            'previous' => $compare ? $this->totals($days, $compare) : null,
            'series' => $series,
            'has_data' => $days[SubscriptionLedger::KIND_RECURRING] !== [] || $days[SubscriptionLedger::KIND_CHECKOUT] !== [],
        ];
    }

    /** "+12.3%"-style change of one kind's revenue (or 'all'). */
    public static function delta(array $q, string $kind, string $measure = 'revenue'): ?float
    {
        if ($q['previous'] === null) {
            return null;
        }

        return Delta::percent($q['totals'][$kind][$measure], $q['previous'][$kind][$measure]);
    }

    /** @return array<string, array{orders: int, revenue: float}> per kind + 'all' */
    private function totals(array $days, Period $window): array
    {
        $from = $window->start()->format(Granularity::DAY_KEY);
        $to = $window->end()->format(Granularity::DAY_KEY);
        $out = ['all' => ['orders' => 0, 'revenue' => 0.0]];
        foreach (self::KINDS as $kind) {
            $out[$kind] = ['orders' => 0, 'revenue' => 0.0];
            foreach ($days[$kind] as $day => $d) {
                if ($day >= $from && $day <= $to) {
                    $out[$kind]['orders'] += $d['orders'];
                    $out[$kind]['revenue'] += $d['revenue'];
                }
            }
            $out[$kind]['revenue'] = round($out[$kind]['revenue'], 2);
            $out['all']['orders'] += $out[$kind]['orders'];
            $out['all']['revenue'] = round($out['all']['revenue'] + $out[$kind]['revenue'], 2);
        }

        return $out;
    }

    /** @return array<string, array<string, array{orders: int, revenue: float}>> kind => day => totals */
    private function days(): array
    {
        $period = $this->context->period;
        $filters = $this->context->filters;

        return AnalyticsCache::remember(self::CACHE_DAYS, $period, $filters, static function () use ($period, $filters): array {
            $from = $period->earliest();
            $to = $period->end();
            $ledger = new SubscriptionLedger($filters);
            $out = array_fill_keys(self::KINDS, []);

            $rows = $ledger->realisedPlanRows()
                ->whereBetween('payment_ledger.created_at', [$from, $to])
                ->selectRaw(Sql::day('payment_ledger.created_at').' as d, '.SubscriptionLedger::kindExpression().' as kind, '.SubscriptionLedger::NET.' as net')
                ->toBase();
            foreach (DB::query()->fromSub($rows, 'u')->selectRaw('d, kind, COUNT(*) as orders, COALESCE(SUM(net), 0) as revenue')->groupBy('d', 'kind')->get() as $r) {
                $out[$r->kind][$r->d] = ['orders' => (int) $r->orders, 'revenue' => (float) $r->revenue];
            }

            $attempts = $ledger->contractAttempts();
            if ($attempts !== null) {
                $at = SubscriptionLedger::attemptAt();
                $rows = $attempts->whereRaw("{$at} >= ? AND {$at} <= ?", [$from, $to])
                    ->selectRaw(Sql::day($at).' as d, COALESCE(subscription_contracts.amount, 0) as net')
                    ->toBase();
                foreach (DB::query()->fromSub($rows, 'u')->selectRaw('d, COUNT(*) as orders, COALESCE(SUM(net), 0) as revenue')->groupBy('d')->get() as $r) {
                    $cur = $out[SubscriptionLedger::KIND_RECURRING][$r->d] ?? ['orders' => 0, 'revenue' => 0.0];
                    $out[SubscriptionLedger::KIND_RECURRING][$r->d] = [
                        'orders' => $cur['orders'] + (int) $r->orders,
                        'revenue' => $cur['revenue'] + (float) $r->revenue,
                    ];
                }
            }
            foreach ($out as &$byDay) {
                ksort($byDay);
            }

            return $out;
        });
    }
}
