<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Cancellations\CancellationLabels;
use App\Domain\Analytics\Cancellations\CancellationReasons;
use App\Domain\Analytics\Cancellations\OrderWiseChurnQuery;
use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Cancellations › Order-wise churn (sketch: OrderWiseChurn.dc.html; spec §6.3).
 *
 * Real: churn by completed orders, the reason trend, churn by reason.
 * Not tracked (no cancellation flow): attempts, saves, save rate, saved MRR,
 * top action — those columns are left out and the table says why, rather
 * than printing zeros. "Top action" is replaced by the most common channel.
 */
final class CancellationsOrderWise extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'OrderWiseChurn.dc.html';

    public const VIEW = 'filament.pages.analytics.cancellations.order_wise';

    public const LANG = 'analytics/cancellations_order_wise.';

    public const FILTERS = [Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const OPTIONS = [
        'source' => ['all', 'customer', 'admin', 'payment_failed', 'automatic'],
        'orders' => ['all', '0', '1', '2', '3', '4', '5', '6', '7', '8'],
    ];

    public const TONES = ['s4', 's3', 's1', 's2', 's5', 's6'];

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $source = $context->option('source', self::OPTIONS['source']);
        $orders = $context->option('orders', self::OPTIONS['orders']);
        $q = (new OrderWiseChurnQuery($context))->get($source, $orders);
        $t = $q['totals'];

        return [
            'has_data' => $q['has_data'],
            'source' => $source,
            'sources' => $this->labels('source', self::OPTIONS['source']),
            'orders' => $orders,
            'order_options' => $this->orderOptions(),
            'summary' => __(self::LANG.'by_orders.summary', [
                'subs' => ChartFormat::value($t['subscriptions']),
                'churned' => ChartFormat::value($t['churned']),
                'rate' => ChartFormat::value($t['rate'] ?? 0, ChartFormat::PERCENT),
                'mrr' => ChartFormat::money($t['mrr']),
                'orders' => $t['orders_before'] === null ? '—' : ChartFormat::value($t['orders_before']),
            ]),
            'by_orders' => $this->byOrders($q['by_orders']),
            'trend' => $this->trend($q, $context),
            'reasons' => $this->reasons($q['reasons']),
        ];
    }

    public function export(Context $context): ?array
    {
        $q = (new OrderWiseChurnQuery($context))->get(
            $context->option('source', self::OPTIONS['source']),
            $context->option('orders', self::OPTIONS['orders']),
        );

        return [
            'headers' => array_map(static fn (string $k): string => __(self::LANG.'by_orders.'.$k), ['orders', 'subscriptions', 'churned', 'rate', 'mrr']),
            'rows' => array_map(static fn (array $r): array => [
                self::bucketLabel($r['bucket']), $r['active'] + $r['cancelled'], $r['cancelled'],
                $r['share'] === null ? '' : number_format($r['share'], 1, '.', ''), number_format($r['mrr'], 2, '.', ''),
            ], $q['by_orders']),
        ];
    }

    public static function bucketLabel(int $bucket): string
    {
        return $bucket >= OrderWiseChurnQuery::BUCKETS
            ? __('analytics/cancellations_overview.order_wise.bucket_plus', ['n' => $bucket])
            : (string) $bucket;
    }

    /** @return array<string, string> */
    private function labels(string $option, array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $out[$value] = __(self::LANG.$option.'.'.$value);
        }

        return $out;
    }

    /** @return array<string, string> */
    private function orderOptions(): array
    {
        $out = [];
        foreach (self::OPTIONS['orders'] as $value) {
            $out[$value] = $value === 'all' ? __(self::LANG.'orders_filter.all') : __(self::LANG.'orders_filter.n', ['n' => self::bucketLabel((int) $value)]);
        }

        return $out;
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>} */
    private function byOrders(array $rows): array
    {
        return [
            'columns' => [
                ['key' => 'orders', 'label' => __(self::LANG.'by_orders.orders'), 'strong' => true],
                ['key' => 'subscriptions', 'label' => __(self::LANG.'by_orders.subscriptions'), 'numeric' => true],
                ['key' => 'churned', 'label' => __(self::LANG.'by_orders.churned'), 'numeric' => true],
                ['key' => 'rate', 'label' => __(self::LANG.'by_orders.rate'), 'numeric' => true],
                ['key' => 'mrr', 'label' => __(self::LANG.'by_orders.mrr'), 'numeric' => true],
            ],
            'rows' => array_map(static fn (array $r): array => [
                'orders' => self::bucketLabel($r['bucket']),
                'subscriptions' => ChartFormat::value($r['active'] + $r['cancelled']),
                'churned' => ChartFormat::value($r['cancelled']),
                'rate' => $r['share'] === null ? '—' : ChartFormat::value($r['share'], ChartFormat::PERCENT),
                'mrr' => ChartFormat::money($r['mrr']),
            ], array_values(array_filter($rows, static fn (array $r): bool => $r['active'] + $r['cancelled'] > 0))),
        ];
    }

    /** @return array<string, mixed> */
    private function trend(array $q, Context $context): array
    {
        $series = [];
        foreach ($q['reason_trend']['series'] as $i => $s) {
            $series[] = [
                'key' => 'r'.$i,
                'label' => CancellationLabels::reason($s['key'], $s['text']).' · '.ChartFormat::value(array_sum($s['values'])),
                'tone' => self::TONES[$i] ?? 's6',
                'values' => $s['values'],
            ];
        }

        return [
            'id' => OrderWiseChurnQuery::CHART_REASONS,
            'labels' => $q['reason_trend']['labels'],
            'series' => $series,
            'grain' => $q['grain']->value,
            'grains' => SubscribersOverview::grainOptions($context->period),
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>} */
    private function reasons(array $rows): array
    {
        return [
            'columns' => [
                ['key' => 'reason', 'label' => __(self::LANG.'reasons.reason'), 'strong' => true],
                ['key' => 'churned', 'label' => __(self::LANG.'reasons.churned'), 'numeric' => true],
                ['key' => 'share', 'label' => __(self::LANG.'reasons.share'), 'numeric' => true],
                ['key' => 'mrr', 'label' => __(self::LANG.'reasons.mrr'), 'numeric' => true],
                ['key' => 'orders', 'label' => __(self::LANG.'reasons.orders'), 'numeric' => true],
                ['key' => 'channel', 'label' => __(self::LANG.'reasons.channel')],
            ],
            'rows' => array_map(static fn (array $r): array => [
                'reason' => CancellationLabels::reason($r['key'], $r['text']),
                'churned' => ChartFormat::value($r['count']),
                'share' => $r['share'] === null ? '—' : ChartFormat::value($r['share'], ChartFormat::PERCENT),
                'mrr' => ChartFormat::money($r['mrr']),
                'orders' => $r['orders_before'] === null ? '—' : ChartFormat::value($r['orders_before']),
                'channel' => $r['channel'] === null ? '—' : [
                    'pill' => $r['channel'] === CancellationReasons::CHANNEL_PAYMENT_FAILED ? 'bad' : 'neutral',
                    'text' => CancellationLabels::channel($r['channel']),
                ],
            ], $rows),
        ];
    }
}
