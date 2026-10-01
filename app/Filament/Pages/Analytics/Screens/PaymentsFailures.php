<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Payments\DeclineReason;
use App\Domain\Analytics\Payments\PaymentsFailuresQuery;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Payments › Failures (sketch: PaymentsFailures.dc.html; spec §3.3).
 * PaymentsFailuresQuery computes; this class shapes; the partial draws.
 *
 * Reasons are PayPlus's free-text decline (failure_code is always 1), grouped
 * into DeclineReason buckets by substring; anything unrecognised is "Other",
 * never guessed. Sources are the two LETS has: the PayPlus saved card and
 * Shopify Payments.
 */
final class PaymentsFailures extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'PaymentsFailures.dc.html';

    public const VIEW = 'filament.pages.analytics.payments.failures';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const LANG = 'analytics/payments_failures.';

    /** Top N reasons get a slice / a series; the rest fold into "Other". */
    public const TOP = 5;

    /** Reductions palette (brief §2): red first — these are failures. */
    public const TONES = ['s4', 's3', 's5', 's2', 's1', 's6'];

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $q = (new PaymentsFailuresQuery($context))->get();
        $f = $q['failures'];
        $reasons = $this->topReasons($q['by_reason']);

        return [
            'has_data' => $q['has_data'],
            'has_attempts' => $q['has_attempts'],
            'total' => [
                'value' => ChartFormat::value($f['failures']),
                'delta' => $q['delta'],
                'compare' => SubscribersOverview::compareCaption($context->period),
                'share' => __(self::LANG.'total.share', [
                    'rate' => PaymentsOverview::pct($f['rate']) ?? '—',
                    'attempts' => ChartFormat::value($f['attempts']),
                ]),
                'rows' => [
                    ['label' => __(self::LANG.'total.entered'), 'value' => ChartFormat::value($f['entered'])],
                    ['label' => __(self::LANG.'total.recovered'), 'value' => ChartFormat::value($f['recovered'])],
                    ['label' => __(self::LANG.'total.under'), 'value' => ChartFormat::value($f['under'])],
                    ['label' => __(self::LANG.'total.lost'), 'value' => ChartFormat::value($f['lost'])],
                ],
            ],
            'sources' => array_values(array_map(
                static fn (string $source, int $n, int $i): array => [
                    'label' => __('analytics/payments_overview.source.'.$source),
                    'value' => $n,
                    'tone' => self::TONES[$i] ?? 's6',
                ],
                array_keys($q['by_source']), $q['by_source'], array_keys(array_keys($q['by_source'])),
            )),
            'reasons' => array_map(static fn (array $r): array => [
                'label' => DeclineReason::label($r['reason']), 'value' => $r['count'], 'tone' => $r['tone'],
            ], $reasons),
            'total_failures' => ChartFormat::value($f['failures']),
            'series' => [
                'id' => PaymentsFailuresQuery::CHART_OVER_TIME,
                'labels' => $q['series']['labels'],
                'bars' => $this->seriesBars($q['series']['reasons'], $reasons),
                'grain' => PaymentsFailuresQuery::grain($context)->value,
                'grains' => SubscribersOverview::grainOptions($context->period),
            ],
        ];
    }

    public function export(Context $context): ?array
    {
        $q = (new PaymentsFailuresQuery($context))->get();
        $total = max(1, $q['failures']['failures']);

        return [
            'headers' => [__(self::LANG.'export.reason'), __(self::LANG.'export.failures'), __(self::LANG.'export.share')],
            'rows' => array_map(
                static fn (string $reason, int $n): array => [DeclineReason::label($reason), $n, round($n / $total * 100, 1)],
                array_keys($q['by_reason']), $q['by_reason'],
            ),
        ];
    }

    /**
     * The top reasons (+ "Other" for the rest), each with its tone.
     *
     * @param  array<string, int>  $byReason
     * @return list<array{reason: string, keys: list<string>, count: int, tone: string}>
     */
    private function topReasons(array $byReason): array
    {
        // "Other" is a real bucket too — it always sorts last and absorbs the tail.
        $other = $byReason[DeclineReason::OTHER] ?? 0;
        unset($byReason[DeclineReason::OTHER]);
        $top = array_slice($byReason, 0, self::TOP, true);
        $rest = array_slice($byReason, self::TOP, null, true);

        $out = [];
        $i = 0;
        foreach ($top as $reason => $n) {
            $out[] = ['reason' => (string) $reason, 'keys' => [(string) $reason], 'count' => $n, 'tone' => self::TONES[$i++] ?? 's6'];
        }
        if ($other + array_sum($rest) > 0) {
            $out[] = [
                'reason' => DeclineReason::OTHER,
                'keys' => [DeclineReason::OTHER, ...array_map('strval', array_keys($rest))],
                'count' => $other + array_sum($rest),
                'tone' => 's6',
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, list<float>>  $series
     * @param  list<array{reason: string, keys: list<string>, count: int, tone: string}>  $reasons
     * @return list<array<string, mixed>>
     */
    private function seriesBars(array $series, array $reasons): array
    {
        $bars = [];
        foreach ($reasons as $r) {
            $values = null;
            foreach ($r['keys'] as $key) {
                foreach ($series[$key] ?? [] as $i => $v) {
                    $values[$i] = ($values[$i] ?? 0) + $v;
                }
            }
            if ($values !== null) {
                $bars[] = ['key' => $r['reason'], 'label' => DeclineReason::label($r['reason']), 'tone' => $r['tone'], 'values' => array_values($values)];
            }
        }

        return $bars;
    }
}
