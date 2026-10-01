<?php

namespace App\Domain\Analytics\Cancellations;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Granularity;

/**
 * Cancellations › Order-wise churn — the numbers (spec §6.3).
 *
 * Churn by completed orders: per bucket, subscriptions = active today at that
 * order count + those cancelled in the window at it; churn rate = cancelled ÷
 * that base. "Completed orders" = succeeded ledger rows (PayPlus) / succeeded
 * billing attempts (Shopify) the subscription had when it was cancelled.
 *
 * The cancellation-source filter narrows the CANCELLED side only (the active
 * base has no source). Attempts, saves and "top action" need a cancellation
 * flow LETS does not have — the screen says so instead of drawing zeros.
 */
final class OrderWiseChurnQuery
{
    // === CONSTANTS ===
    public const CHART_REASONS = 'reasons_by_orders';

    /** Order-count rows 0…7, then "8+" (the sketch's table). */
    public const BUCKETS = 8;

    public const ORDERS_ALL = 'all';

    public function __construct(private readonly Context $context) {}

    /** @return array<string, mixed> */
    public function get(string $source, string $orders): array
    {
        $period = $this->context->period;
        $channels = CancellationReasons::channelsFor($source);

        $data = new ChurnData($this->context);
        $all = ChurnData::inWindow($data->cancellations($period->start(), $period->end()), $period);
        $rows = array_values(array_filter($all, static fn (array $r): bool => in_array($r['channel'], $channels, true)));
        $breakdown = (new ActiveBreakdown($this->context->filters))->rows();

        $byOrders = CancellationsOverviewQuery::orderWise($breakdown, $rows, self::BUCKETS);
        $trendRows = $orders === self::ORDERS_ALL
            ? $rows
            : array_values(array_filter($rows, static fn (array $r): bool => min($r['orders'], self::BUCKETS) === (int) $orders));

        $grain = $this->context->grain(self::CHART_REASONS, Granularity::WEEKLY);
        $count = count($rows);

        return [
            'by_orders' => $byOrders,
            'totals' => [
                'subscriptions' => array_sum(array_column($byOrders, 'active')) + $count,
                'churned' => $count,
                'rate' => self::rate($count, array_sum(array_column($byOrders, 'active')) + $count),
                'mrr' => round(array_sum(array_column($rows, 'mrr')), 2),
                'orders_before' => $count > 0 ? round(array_sum(array_column($rows, 'orders')) / $count, 1) : null,
            ],
            'reason_trend' => CancellationsOverviewQuery::reasonSeries($trendRows, $period, $grain),
            'grain' => $grain,
            'reasons' => $this->reasonTable($rows),
            'has_data' => $all !== [] || array_sum(array_column($breakdown, 'subscriptions')) > 0,
        ];
    }

    /** @return list<array{key: string, text: string, count: int, share: ?float, mrr: float, orders_before: ?float, channel: ?string}> */
    private function reasonTable(array $rows): array
    {
        $total = count($rows);
        $channels = [];
        foreach ($rows as $r) {
            $channels[$r['reason_key']][$r['channel']] = ($channels[$r['reason_key']][$r['channel']] ?? 0) + 1;
        }

        return array_map(static function (array $r) use ($total, $channels): array {
            $c = $channels[$r['key']] ?? [];
            arsort($c);

            return [
                'key' => $r['key'],
                'text' => $r['text'],
                'count' => $r['count'],
                'share' => self::rate($r['count'], $total),
                'mrr' => $r['mrr'],
                'orders_before' => $r['count'] > 0 ? round($r['orders'] / $r['count'], 1) : null,
                'channel' => $c === [] ? null : (string) array_key_first($c),
            ];
        }, CancellationsOverviewQuery::reasons($rows));
    }

    private static function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
