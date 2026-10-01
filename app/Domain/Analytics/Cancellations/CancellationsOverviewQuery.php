<?php

namespace App\Domain\Analytics\Cancellations;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Subscribers\MovementSummary;
use Carbon\CarbonImmutable;

/**
 * Cancellations › Overview — the numbers (spec §6.1). No formatting, no copy.
 *
 * Definitions (docs/analytics/data-map.md §6):
 *   subscriber churn rate   = subscribers lost in the window ÷ active subscribers
 *                             at its start (MovementSummary::churn — the same
 *                             walker as Subscribers › Overview);
 *   cancellation rate       = subscriptions cancelled ÷ active subscriptions at
 *                             the window's start;
 *   orders before cancel    = mean completed orders of the cancelled ones;
 *   MRR lost                = Σ MRR of the subscriptions cancelled.
 * Breakdown shares ("x of y · z%") divide by active TODAY + cancelled in the
 * window — the subscriptions that were in that slice during the window.
 *
 * The trend charts look further back than the window on purpose (the sketch:
 * six months, or twelve weeks) — a churn trend over 30 days is two points.
 */
final class CancellationsOverviewQuery
{
    // === CONSTANTS ===
    public const CHART_TREND = 'churn_trend';

    public const CHART_REASON_TREND = 'reason_trend';

    public const TREND_MONTHS = 6;

    public const TREND_WEEKS = 12;

    /** Order-count buckets 0…9 and a last "10+" bucket. */
    public const ORDER_BUCKETS = 10;

    public const TOP_PRODUCTS = 10;

    /** Reasons drawn by name; the rest fold into "Other". */
    public const TOP_REASONS = 5;

    public const OTHER = '__other';

    public function __construct(private readonly Context $context) {}

    /** The trend charts offer weekly or monthly only (a daily churn rate is noise). */
    public static function trendGrain(Context $context, string $chartId): Granularity
    {
        $grain = $context->grain($chartId, Granularity::MONTHLY);

        return $grain === Granularity::DAILY ? Granularity::WEEKLY : $grain;
    }

    /** The trend window ending on the period's last day. */
    public static function trendWindow(Period $period, Granularity $grain): Period
    {
        $end = $period->end()->startOfDay();
        $start = $grain === Granularity::MONTHLY
            ? $end->startOfMonth()->subMonthsNoOverflow(self::TREND_MONTHS - 1)
            : $end->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(self::TREND_WEEKS - 1);

        return ChurnData::span($start, $end);
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $period = $this->context->period;
        $compare = $period->comparison();
        $trendGrain = self::trendGrain($this->context, self::CHART_TREND);
        $reasonGrain = self::trendGrain($this->context, self::CHART_REASON_TREND);
        $trendWindow = self::trendWindow($period, $trendGrain);
        $reasonWindow = self::trendWindow($period, $reasonGrain);

        $since = $period->earliest()->min($trendWindow->start())->min($reasonWindow->start());
        $data = new ChurnData($this->context);
        $summary = $data->summary($since);
        $log = $data->cancellations($since, $period->end());
        $inPeriod = ChurnData::inWindow($log, $period);
        $breakdown = (new ActiveBreakdown($this->context->filters))->rows();

        return [
            'subscriber' => $this->subscriberMetrics($summary, $period),
            'subscriber_previous' => $compare ? $this->subscriberMetrics($summary, $compare) : null,
            'subscription' => $this->subscriptionMetrics($summary, $log, $period),
            'subscription_previous' => $compare ? $this->subscriptionMetrics($summary, $log, $compare) : null,
            'trend' => $this->trend($summary, $log, $trendWindow, $trendGrain),
            'trend_grain' => $trendGrain,
            'products' => $this->products($breakdown, $inPeriod),
            'order_wise' => self::orderWise($breakdown, $inPeriod),
            'reasons' => self::reasons($inPeriod),
            'channels' => self::channels($inPeriod),
            'reason_trend' => $this->reasonTrend($log, $reasonWindow, $reasonGrain),
            'reason_grain' => $reasonGrain,
            'by_frequency' => self::byDimension($breakdown, $inPeriod, 'freq'),
            'by_plan' => self::byDimension($breakdown, $inPeriod, 'sp'),
            'has_data' => $log !== [] || array_sum(array_column($breakdown, 'subscriptions')) > 0,
        ];
    }

    /** @return array{rate: ?float, lost: int, start_active: int, zero_day: int, zero_day_share: ?float} */
    private function subscriberMetrics(MovementSummary $summary, Period $window): array
    {
        $c = $summary->churn($window);

        return [
            'rate' => $c['rate'],
            'lost' => $c['lost'],
            'start_active' => $c['start_active'],
            'zero_day' => $c['zero_day'],
            'zero_day_share' => $c['zero_day_share'],
        ];
    }

    /** @return array{rate: ?float, cancelled: int, start_active: int, orders_before: ?float, mrr_lost: float, mrr_share: ?float} */
    private function subscriptionMetrics(MovementSummary $summary, array $log, Period $window): array
    {
        $before = $window->start()->subSecond();
        $startActive = $summary->activeAt(MovementSummary::LEVEL_SUBSCRIPTION, $before);
        $rows = ChurnData::inWindow($log, $window);
        $cancelled = count($rows);
        $mrrLost = round(array_sum(array_column($rows, 'mrr')), 2);
        $startMrr = $summary->mrrAt($before);

        return [
            'rate' => $startActive > 0 ? round($cancelled / $startActive * 100, 1) : null,
            'cancelled' => $cancelled,
            'start_active' => $startActive,
            'orders_before' => $cancelled > 0 ? round(array_sum(array_column($rows, 'orders')) / $cancelled, 1) : null,
            'mrr_lost' => $mrrLost,
            'mrr_share' => $startMrr > 0 ? round($mrrLost / $startMrr * 100, 1) : null,
        ];
    }

    /**
     * One value per bucket of the trend window, for every metric the selector offers.
     *
     * @return array{labels: list<string>, lost: list<int>, churn_rate: list<?float>, cancelled: list<int>, cancellation_rate: list<?float>, orders_before: list<?float>, mrr_lost: list<float>}
     */
    private function trend(MovementSummary $summary, array $log, Period $window, Granularity $grain): array
    {
        $out = ['labels' => [], 'lost' => [], 'churn_rate' => [], 'cancelled' => [], 'cancellation_rate' => [], 'orders_before' => [], 'mrr_lost' => []];
        foreach ($grain->buckets($window) as $bucket) {
            $span = ChurnData::span($bucket['start'], $bucket['end']);
            $sub = $this->subscriberMetrics($summary, $span);
            $subs = $this->subscriptionMetrics($summary, $log, $span);
            $out['labels'][] = $bucket['label'];
            $out['lost'][] = $sub['lost'];
            $out['churn_rate'][] = $sub['rate'];
            $out['cancelled'][] = $subs['cancelled'];
            $out['cancellation_rate'][] = $subs['rate'];
            $out['orders_before'][] = $subs['orders_before'];
            $out['mrr_lost'][] = $subs['mrr_lost'];
        }

        return $out;
    }

    /**
     * Top products by MRR cancelled in the window, with their active MRR today.
     *
     * @return list<array{product: string, title: string, active_mrr: float, cancelled_mrr: float, cancelled: int, share: ?float}>
     */
    private function products(array $breakdown, array $rows): array
    {
        $active = [];
        foreach ($breakdown as $b) {
            $active[$b['product']]['mrr'] = ($active[$b['product']]['mrr'] ?? 0) + $b['mrr'];
            $active[$b['product']]['title'] ??= $b['product_title'];
        }

        $out = [];
        foreach ($rows as $r) {
            $p = $r['product'];
            $out[$p] ??= ['product' => $p, 'title' => $r['product_title'] ?: (string) ($active[$p]['title'] ?? ''), 'cancelled_mrr' => 0.0, 'cancelled' => 0];
            $out[$p]['cancelled_mrr'] += $r['mrr'];
            $out[$p]['cancelled']++;
        }

        foreach ($out as $p => &$row) {
            $row['active_mrr'] = round((float) ($active[$p]['mrr'] ?? 0), 2);
            $row['cancelled_mrr'] = round($row['cancelled_mrr'], 2);
            $base = $row['active_mrr'] + $row['cancelled_mrr'];
            $row['share'] = $base > 0 ? round($row['cancelled_mrr'] / $base * 100, 1) : null;
        }
        unset($row);

        $out = array_values($out);
        usort($out, static fn (array $a, array $b): int => [$b['cancelled_mrr'], $b['cancelled']] <=> [$a['cancelled_mrr'], $a['cancelled']]);

        return array_slice($out, 0, self::TOP_PRODUCTS);
    }

    /** The bucket of an order count: 0…9, then ORDER_BUCKETS for "10+". */
    public static function orderBucket(int $orders): int
    {
        return min(max(0, $orders), self::ORDER_BUCKETS);
    }

    /**
     * Active subscriptions (today) and cancellations (in the window) per completed-order bucket.
     *
     * @return list<array{bucket: int, active: int, cancelled: int, mrr: float, share: ?float}>
     */
    public static function orderWise(array $breakdown, array $rows, int $buckets = self::ORDER_BUCKETS): array
    {
        $out = [];
        for ($i = 0; $i <= $buckets; $i++) {
            $out[$i] = ['bucket' => $i, 'active' => 0, 'cancelled' => 0, 'mrr' => 0.0, 'share' => null];
        }
        foreach ($breakdown as $b) {
            $out[min($b['orders'], $buckets)]['active'] += $b['subscriptions'];
        }
        foreach ($rows as $r) {
            $i = min($r['orders'], $buckets);
            $out[$i]['cancelled']++;
            $out[$i]['mrr'] += $r['mrr'];
        }
        foreach ($out as &$row) {
            $base = $row['active'] + $row['cancelled'];
            $row['share'] = $base > 0 ? round($row['cancelled'] / $base * 100, 1) : null;
            $row['mrr'] = round($row['mrr'], 2);
        }
        unset($row);

        return array_values($out);
    }

    /**
     * Cancellations per reason group, biggest first.
     *
     * @return list<array{key: string, text: string, count: int, mrr: float}>
     */
    public static function reasons(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = $r['reason_key'];
            $out[$k] ??= ['key' => $k, 'text' => CancellationReasons::isSystemReason($k) ? '' : $r['reason'], 'count' => 0, 'mrr' => 0.0, 'orders' => 0];
            $out[$k]['count']++;
            $out[$k]['mrr'] += $r['mrr'];
            $out[$k]['orders'] += $r['orders'];
        }
        $out = array_values($out);
        usort($out, static fn (array $a, array $b): int => [$b['count'], $b['mrr']] <=> [$a['count'], $a['mrr']]);

        return array_map(static fn (array $r): array => [...$r, 'mrr' => round($r['mrr'], 2)], $out);
    }

    /** @return list<array{key: string, count: int}> cancellations per channel, biggest first */
    public static function channels(array $rows): array
    {
        $counts = array_count_values(array_column($rows, 'channel'));
        arsort($counts);
        $out = [];
        foreach ($counts as $key => $count) {
            $out[] = ['key' => (string) $key, 'count' => (int) $count];
        }

        return $out;
    }

    /**
     * One line per top reason (+ Other) over the trend window.
     *
     * @return array{labels: list<string>, series: list<array{key: string, text: string, values: list<int>}>}
     */
    private function reasonTrend(array $log, Period $window, Granularity $grain): array
    {
        return self::reasonSeries(ChurnData::inWindow($log, $window), $window, $grain);
    }

    /** @return array{labels: list<string>, series: list<array{key: string, text: string, values: list<int>}>} */
    public static function reasonSeries(array $rows, Period $window, Granularity $grain): array
    {
        $buckets = $grain->buckets($window);
        $index = $grain->dayIndex($window);
        $top = array_slice(self::reasons($rows), 0, self::TOP_REASONS);
        $keys = array_column($top, 'key');

        $series = [];
        foreach ($top as $t) {
            $series[$t['key']] = ['key' => $t['key'], 'text' => $t['text'], 'values' => array_fill(0, count($buckets), 0)];
        }
        $other = array_fill(0, count($buckets), 0);
        $hasOther = false;

        foreach ($rows as $r) {
            $i = $index[$r['day']] ?? null;
            if ($i === null) {
                continue;
            }
            if (in_array($r['reason_key'], $keys, true)) {
                $series[$r['reason_key']]['values'][$i]++;
            } else {
                $other[$i]++;
                $hasOther = true;
            }
        }
        if ($hasOther) {
            $series[self::OTHER] = ['key' => self::OTHER, 'text' => '', 'values' => $other];
        }

        return ['labels' => array_column($buckets, 'label'), 'series' => array_values($series)];
    }

    /**
     * Active (today) vs cancelled (window) per value of one dimension (freq | sp).
     *
     * @return list<array{key: string, name: ?string, active: int, cancelled: int, share: ?float}>
     */
    public static function byDimension(array $breakdown, array $rows, string $dimension): array
    {
        $out = [];
        foreach ($breakdown as $b) {
            $k = $b[$dimension];
            $out[$k] ??= ['key' => $k, 'name' => $b['sp_name'] ?? null, 'active' => 0, 'cancelled' => 0];
            $out[$k]['active'] += $b['subscriptions'];
        }
        foreach ($rows as $r) {
            $k = $r[$dimension];
            $out[$k] ??= ['key' => $k, 'name' => $r['sp_name'] ?? null, 'active' => 0, 'cancelled' => 0];
            $out[$k]['cancelled']++;
        }
        foreach ($out as &$row) {
            $base = $row['active'] + $row['cancelled'];
            $row['share'] = $base > 0 ? round($row['cancelled'] / $base * 100, 1) : null;
        }
        unset($row);

        $out = array_values($out);
        usort($out, static fn (array $a, array $b): int => [$b['active'], $b['cancelled']] <=> [$a['active'], $a['cancelled']]);

        return $out;
    }
}
