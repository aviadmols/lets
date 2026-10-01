<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Products\ProductsChurnQuery;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Products › Churn & retention (sketch ProductsChurn.dc.html; spec §4.3).
 * ProductsChurnQuery computes; this class shapes; the partial draws.
 *
 * Honest gaps (docs/analytics/data-map.md §4): LETS has no cancellation flow,
 * so "Cancellation flow effectiveness", attempts and saves render NOT TRACKED;
 * reasons are the free text on the cancel event (◐), not a reason list.
 */
final class ProductsChurn extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'ProductsChurn.dc.html';

    public const VIEW = 'filament.pages.analytics.products.churn';

    public const LANG = 'analytics/products_churn';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const OPTION_MEASURE = 'measure';

    public const OPTIONS = [self::OPTION_MEASURE => ProductsChurnQuery::MEASURES];

    /** Churn is a reduction: amber/red/gray first, then the remaining tones. */
    public const LINE_TONES = ['s4', 's3', 's6', 's5', 's2', 's1'];

    public const SLICE_TONES = ['s4', 's3', 's6', 's5', 's2'];

    /** Reason markers LETS writes itself (not merchant free text). */
    public const REASON_MARKERS = ['customer_portal', 'customer_area'];

    public const MAX_REASONS = 5;

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $measure = $context->option(self::OPTION_MEASURE, self::OPTIONS[self::OPTION_MEASURE]);
        $q = (new ProductsChurnQuery($context, $measure))->get();
        $isMrr = $measure === ProductsChurnQuery::MEASURE_MRR;
        $format = $isMrr ? ChartFormat::MONEY : ChartFormat::NUMBER;
        $total = $isMrr ? $q['churned']['mrr'] : $q['churned']['qty'];

        return [
            'has_data' => $q['has_data'],
            'measure' => $measure,
            'measures' => [
                ProductsChurnQuery::MEASURE_MRR => __(self::LANG.'.measure.mrr'),
                ProductsChurnQuery::MEASURE_QUANTITY => __(self::LANG.'.measure.quantity'),
            ],
            'format' => $format,
            'trend' => $this->trend($q, $context, $format),
            'trend_subtitle' => __(self::LANG.'.trend.subtitle_'.$measure, [
                'top' => ChartFormat::value($q['top_churned'], $format),
                'total' => ChartFormat::value($total, $format),
            ]),
            'reasons' => $this->reasons($q['reasons'], $isMrr),
            'reasons_total' => ChartFormat::value($total, $format),
            'table' => $this->table($q),
        ];
    }

    public function export(Context $context): ?array
    {
        $table = $this->table((new ProductsChurnQuery($context))->get());

        return [
            'headers' => array_column($table['columns'], 'label'),
            'rows' => array_map(static fn (array $r): array => array_values($r), $table['rows']),
        ];
    }

    // === Shaping ===

    /** @return array<string, mixed> */
    private function trend(array $q, Context $context, string $format): array
    {
        $series = [];
        $i = 0;
        foreach ($q['trend']['products'] as $pk => $values) {
            $series[] = [
                'key' => 'p'.$i,
                'label' => ProductsOverview::productLabel(self::LANG, (string) $pk, $q['names'][$pk] ?? null),
                'tone' => self::LINE_TONES[$i] ?? 's6',
                'values' => $values,
            ];
            $i++;
        }
        if ($q['trend']['other'] !== [] && array_sum($q['trend']['other']) > 0) {
            $series[] = ['key' => 'other', 'label' => __('analytics.other'), 'tone' => 's6', 'values' => $q['trend']['other'], 'dashed' => true];
        }

        return [
            'id' => ProductsChurnQuery::CHART_TREND,
            'labels' => $q['trend']['labels'],
            'series' => $series,
            'grain' => ProductsChurnQuery::grain($context)->value,
            'grains' => SubscribersOverview::grainOptions($context->period),
        ];
    }

    /**
     * @param  list<array{reason: string, qty: int, mrr: float}>  $reasons
     * @return list<array<string, mixed>>
     */
    private function reasons(array $reasons, bool $isMrr): array
    {
        $field = $isMrr ? 'mrr' : 'qty';
        usort($reasons, static fn (array $a, array $b): int => $b[$field] <=> $a[$field]);
        $reasons = array_values(array_filter($reasons, static fn (array $r): bool => $r[$field] > 0));
        $top = array_slice($reasons, 0, self::MAX_REASONS);
        $rest = array_slice($reasons, self::MAX_REASONS);

        $out = [];
        foreach ($top as $i => $r) {
            $out[] = ['label' => $this->reasonLabel($r['reason']), 'value' => $r[$field], 'tone' => self::SLICE_TONES[$i] ?? 's6'];
        }
        if ($rest !== []) {
            $out[] = ['label' => __('analytics.other'), 'value' => array_sum(array_column($rest, $field)), 'tone' => 's6'];
        }

        return $out;
    }

    private function reasonLabel(string $reason): string
    {
        return match (true) {
            $reason === ProductsChurnQuery::REASON_NONE => __(self::LANG.'.reasons.none'),
            $reason === ProductsChurnQuery::REASON_PAYMENT_FAILED => __(self::LANG.'.reasons.payment_failed'),
            in_array($reason, self::REASON_MARKERS, true) => __(self::LANG.'.reasons.'.$reason),
            default => $reason,
        };
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, string>>} */
    private function table(array $q): array
    {
        $l = self::LANG.'.table.';

        return [
            'columns' => [
                ['key' => 'product', 'label' => __($l.'product'), 'strong' => true],
                ['key' => 'variant', 'label' => __($l.'variant')],
                ['key' => 'subscribed', 'label' => __($l.'subscribed'), 'numeric' => true],
                ['key' => 'attempts', 'label' => __($l.'attempts'), 'numeric' => true],
                ['key' => 'saved', 'label' => __($l.'saved'), 'numeric' => true],
                ['key' => 'cancelled', 'label' => __($l.'cancelled'), 'numeric' => true],
                ['key' => 'rate', 'label' => __($l.'rate'), 'numeric' => true],
            ],
            'rows' => array_map(static fn (array $r): array => [
                'product' => ProductsOverview::productLabel(self::LANG, $r['pk'], $r['title']),
                'variant' => $r['variant'] ?? '—',
                'subscribed' => ChartFormat::value($r['subscribed']),
                'attempts' => '—', // not tracked: no cancellation flow
                'saved' => '—',
                'cancelled' => ChartFormat::value($r['cancelled']),
                'rate' => $r['rate'] === null ? '—' : ChartFormat::value($r['rate'], ChartFormat::PERCENT),
            ], $q['table']),
        ];
    }
}
