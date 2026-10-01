<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Subscribers\OrderFunnelQuery;
use App\Domain\Analytics\Support\Delta;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Subscribers › Order funnel (sketch OrderFunnel.dc.html; spec §1.3).
 * OrderFunnelQuery computes; this class shapes; the partial draws.
 *
 * Honest partial (docs/analytics/data-map.md §1.3): stage 2 (attempted →
 * outcome) is tracked on both rails. Stage 1 (scheduled → attempted, and its
 * rescheduled / skipped / paused / cancelled leaks) is NOT — a due charge that
 * was never attempted leaves no row — so it renders the not-tracked state and
 * the leakage table carries only the tracked columns.
 */
final class SubscribersOrderFunnel extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'OrderFunnel.dc.html';

    public const VIEW = 'filament.pages.analytics.subscribers.order_funnel';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const OPTION_ORDERS = 'orders';

    public const OPTION_UNIT = 'unit';

    public const UNIT_COUNT = 'count';

    public const UNIT_PERCENT = 'percent';

    public const OPTIONS = [
        self::OPTION_ORDERS => OrderFunnelQuery::MODES,
        self::OPTION_UNIT => [self::UNIT_COUNT, self::UNIT_PERCENT],
    ];

    public const LANG = 'analytics/subscribers_order_funnel.';

    /** "12+" / "#8+": the plus, followed by a left-to-right mark so RTL keeps it after the number. */
    public const PLUS = "+\u{200E}";

    /** Leakage columns after "Attempted", in board order. */
    public const LEAK_COLUMNS = [OrderFunnelQuery::SUCCESS, OrderFunnelQuery::RETRYING, OrderFunnelQuery::FAILED, OrderFunnelQuery::PENDING];

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $mode = $context->option(self::OPTION_ORDERS, self::OPTIONS[self::OPTION_ORDERS]);
        $unit = $context->option(self::OPTION_UNIT, self::OPTIONS[self::OPTION_UNIT]);
        $q = (new OrderFunnelQuery($context))->get($mode);

        return [
            'has_data' => $q['has_data'],
            'order_wise' => $this->orderWise($q['order_wise']),
            'mode' => $mode,
            'modes' => $this->optionLabels(self::OPTION_ORDERS, 'mode'),
            'funnel' => $this->funnel($q['funnel']),
            'unit' => $unit,
            'units' => $this->optionLabels(self::OPTION_UNIT, 'unit'),
            'leakage' => $this->leakage($q['leakage'], $unit),
        ];
    }

    public function export(Context $context): ?array
    {
        $mode = $context->option(self::OPTION_ORDERS, self::OPTIONS[self::OPTION_ORDERS]);
        $table = $this->leakage((new OrderFunnelQuery($context))->get($mode)['leakage'], self::UNIT_COUNT);

        return [
            'headers' => array_column($table['columns'], 'label'),
            'rows' => array_map(static fn (array $r): array => array_values($r), $table['rows']),
        ];
    }

    /** @return array<string, string> option value => label */
    private function optionLabels(string $option, string $langGroup): array
    {
        $out = [];
        foreach (self::OPTIONS[$option] as $value) {
            $out[$value] = __(self::LANG.$langGroup.'.'.$value);
        }

        return $out;
    }

    /** @param array<int, int> $buckets @return array<string, mixed> */
    private function orderWise(array $buckets): array
    {
        $cap = OrderFunnelQuery::ORDER_WISE_CAP;
        $keys = range(isset($buckets[0]) ? 0 : 1, $cap);
        $labels = array_map(static fn (int $n): string => $n === $cap ? $n.self::PLUS : (string) $n, $keys);
        $values = array_map(static fn (int $n): int => $buckets[$n] ?? 0, $keys);
        $total = array_sum($values);

        return [
            'total' => $total,
            'display' => ChartFormat::value($total),
            'labels' => $labels,
            'caps' => array_map(static fn (int $v): string => ChartFormat::value($v), $values),
            'bars' => [
                [
                    'key' => 'orders',
                    'label' => __(self::LANG.'order_wise.series'),
                    'tone' => 's1',
                    'values' => array_map(static fn (int $n, int $v): int => $n === $cap ? 0 : $v, $keys, $values),
                ],
                [
                    'key' => 'loyal',
                    'label' => __(self::LANG.'order_wise.loyal', ['n' => $cap]),
                    'tone' => 's2',
                    'values' => array_map(static fn (int $n, int $v): int => $n === $cap ? $v : 0, $keys, $values),
                ],
            ],
            'loyal' => $buckets[$cap] ?? 0,
        ];
    }

    /** @param array<string, int> $f @return array<string, mixed> */
    private function funnel(array $f): array
    {
        $attempted = array_sum($f);
        $leak = static fn (string $key, int $value): array => [
            'label' => __(self::LANG.'funnel.leak', [
                'label' => __(self::LANG.'outcome.'.$key),
                'share' => ChartFormat::value(Delta::share($value, max(1, $attempted)), ChartFormat::PERCENT),
            ]),
            'value' => $value,
        ];

        return [
            'attempted' => $attempted,
            'stages' => [
                ['label' => __(self::LANG.'funnel.attempted'), 'value' => $attempted, 'tone' => 's1'],
                [
                    'label' => __(self::LANG.'outcome.'.OrderFunnelQuery::SUCCESS),
                    'value' => $f[OrderFunnelQuery::SUCCESS],
                    'tone' => 's2',
                    'leaks' => array_values(array_filter([
                        $leak(OrderFunnelQuery::RETRYING, $f[OrderFunnelQuery::RETRYING]),
                        $leak(OrderFunnelQuery::FAILED, $f[OrderFunnelQuery::FAILED]),
                        $f[OrderFunnelQuery::PENDING] > 0 ? $leak(OrderFunnelQuery::PENDING, $f[OrderFunnelQuery::PENDING]) : null,
                    ])),
                ],
            ],
        ];
    }

    /** @param array<int, array<string, int>> $buckets @return array{columns: list<array<string, mixed>>, rows: list<array<string, string>>} */
    private function leakage(array $buckets, string $unit): array
    {
        $cap = OrderFunnelQuery::LEAKAGE_CAP;
        $columns = [
            ['key' => 'order', 'label' => __(self::LANG.'leakage.order'), 'strong' => true],
            ['key' => 'attempted', 'label' => __(self::LANG.'funnel.attempted'), 'numeric' => true],
        ];
        foreach (self::LEAK_COLUMNS as $key) {
            $columns[] = ['key' => $key, 'label' => __(self::LANG.'outcome.'.$key), 'numeric' => true];
        }

        $row = function (string $label, array $counts) use ($unit): array {
            $attempted = array_sum($counts);
            $out = ['order' => $label, 'attempted' => ChartFormat::value($attempted)];
            foreach (self::LEAK_COLUMNS as $key) {
                $out[$key] = $unit === self::UNIT_PERCENT
                    ? ChartFormat::value(Delta::share($counts[$key] ?? 0, $attempted), ChartFormat::PERCENT)
                    : ChartFormat::value($counts[$key] ?? 0);
            }

            return $out;
        };

        $rows = [];
        $total = array_fill_keys(OrderFunnelQuery::OUTCOMES, 0);
        foreach ($buckets as $n => $counts) {
            $rows[] = $row('#'.$n.($n === $cap ? self::PLUS : ''), $counts);
            foreach ($counts as $k => $v) {
                $total[$k] += $v;
            }
        }
        if ($rows !== []) {
            $rows[] = $row(__(self::LANG.'leakage.all'), $total);
        }

        return ['columns' => $columns, 'rows' => $rows];
    }
}
