<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Payments\JourneyStats;
use App\Domain\Analytics\Payments\PaymentJourneys;
use App\Domain\Analytics\Payments\PaymentsOverviewQuery;
use App\Support\Ui\Charts\ChartFormat;
use Carbon\CarbonImmutable;

/**
 * Payments › Overview (sketch: PaymentsOverview.dc.html; spec §3.1).
 * PaymentsOverviewQuery computes; this class shapes; the partial draws.
 *
 * Honest gaps (data-map §3): backup-card metrics (LETS charges ONE saved
 * token per plan — there is no backup attempt), the country split (no
 * customer country is stored) and the "skipped" share of lost payments (a
 * forgiven cycle is recorded per plan, not per payment) render as not
 * tracked, never as zeros. Shopify-Payments attempts carry no retry history,
 * so their recovery columns read "—".
 */
final class PaymentsOverview extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'PaymentsOverview.dc.html';

    public const VIEW = 'filament.pages.analytics.payments.overview';

    public const FILTERS = [Filters::COUNTRY, Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const OPTIONS = ['unit' => PaymentsOverviewQuery::UNITS];

    public const LANG = 'analytics/payments_overview.';

    /** Outcome → series tone (realized blue, recovered teal, under amber, lost red). */
    public const TONES = ['first' => 's1', 'recovered' => 's2', 'under' => 's3', 'lost' => 's4'];

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $q = (new PaymentsOverviewQuery($context))->get();
        $compare = SubscribersOverview::compareCaption($context->period);
        $format = $q['money'] ? ChartFormat::MONEY : ChartFormat::NUMBER;

        return [
            'has_data' => $q['has_data'],
            'unit' => $context->option('unit', self::OPTIONS['unit']),
            'units' => [
                PaymentsOverviewQuery::UNIT_COUNT => __(self::LANG.'unit.count'),
                PaymentsOverviewQuery::UNIT_REVENUE => __(self::LANG.'unit.revenue'),
            ],
            'kpis' => $this->kpis($q, $compare, $format),
            'monthly' => $this->monthly($q, $format),
            'first_attempt' => [
                ['label' => __(self::LANG.'first_attempt.rate'), 'value' => self::pct($q['first_attempt']['rate'])],
                ['label' => __(self::LANG.'first_attempt.attempted'), 'value' => ChartFormat::value($q['first_attempt']['attempted'])],
                ['label' => __(self::LANG.'first_attempt.realized'), 'value' => ChartFormat::money($q['first_attempt']['realized'])],
            ],
            'first_cycle' => self::cycle($q['first_cycle']),
            'subsequent_cycle' => self::cycle($q['subsequent_cycle']),
            'has_journeys' => $q['has_journeys'],
            'sources' => $this->sources($q, $format),
            'over_time' => [
                'id' => PaymentsOverviewQuery::CHART_OVER_TIME,
                'labels' => $q['over_time']['labels'],
                'bars' => [
                    ['key' => 'first', 'label' => __(self::LANG.'over_time.first'), 'tone' => self::TONES['first'], 'values' => $q['over_time']['first']],
                    ['key' => 'recovered', 'label' => __(self::LANG.'over_time.recovered'), 'tone' => self::TONES['recovered'], 'values' => $q['over_time']['recovered']],
                    ['key' => 'under', 'label' => __(self::LANG.'over_time.under'), 'tone' => self::TONES['under'], 'values' => $q['over_time']['under']],
                    ['key' => 'lost', 'label' => __(self::LANG.'over_time.lost'), 'tone' => self::TONES['lost'], 'values' => $q['over_time']['lost']],
                ],
                'format' => $format,
                'grain' => PaymentsOverviewQuery::grain($context)->value,
                'grains' => SubscribersOverview::grainOptions($context->period),
            ],
        ];
    }

    public function export(Context $context): ?array
    {
        $q = (new PaymentsOverviewQuery($context))->get();
        $m = $q['monthly'];
        $rows = [];
        foreach ($m['months'] as $i => $month) {
            $rows[] = [$month, $m['realized'][$i], $m['under'][$i], $m['lost'][$i], $m['success'][$i]];
        }

        return [
            'headers' => [
                __('analytics.table.period'),
                __(self::LANG.'monthly.realized'),
                __(self::LANG.'monthly.under'),
                __(self::LANG.'monthly.lost'),
                __(self::LANG.'monthly.success'),
            ],
            'rows' => $rows,
        ];
    }

    // === Shared with Payments › Recovery ===

    /**
     * A recovery stage as mini stats + its lost breakdown.
     *
     * @param  array<string, mixed>  $c  JourneyStats::cycle()
     * @return array{stats: list<array{label: string, value: string}>, lost: list<array<string, mixed>>, lost_line: string}
     */
    public static function cycle(array $c): array
    {
        $l = 'analytics/payments_overview.cycle.';

        return [
            'stats' => [
                ['label' => __($l.'rate'), 'value' => self::pct($c['rate'])],
                ['label' => __($l.'attempted'), 'value' => ChartFormat::value($c['attempted'])],
                ['label' => __($l.'realized'), 'value' => ChartFormat::money($c['realized'])],
                ['label' => __($l.'under'), 'value' => ChartFormat::value($c['under'])],
                ['label' => __($l.'lost'), 'value' => ChartFormat::value($c['lost'])],
            ],
            'lost' => [
                ['label' => __($l.'lost_breakdown'), 'value' => ChartFormat::value($c['lost']), 'kind' => 'group'],
                ['label' => __($l.'lost_failed'), 'value' => ChartFormat::value($c['lost_failed'])],
                ['label' => __($l.'lost_stopped'), 'value' => ChartFormat::value($c['lost_stopped'])],
                ['label' => __($l.'lost_skipped'), 'value' => null],
            ],
            'lost_line' => __($l.'lost_line', [
                'lost' => ChartFormat::value($c['lost']),
                'failed' => ChartFormat::value($c['lost_failed']),
                'stopped' => ChartFormat::value($c['lost_stopped']),
            ]),
        ];
    }

    public static function pct(?float $value): ?string
    {
        return $value === null ? null : ChartFormat::value($value, ChartFormat::PERCENT);
    }

    // === Shaping ===

    /** @return list<array<string, mixed>> */
    private function kpis(array $q, ?string $compare, string $format): array
    {
        $k = $q['kpis'];
        $card = static fn (string $key, ?string $value, ?float $delta, bool $goodUp = true, string $unit = 'percent'): array => [
            'label' => __(self::LANG.'kpi.'.$key),
            'value' => $value,
            'delta' => $delta,
            'goodUp' => $goodUp,
            'unit' => $unit,
            'compare' => $compare,
        ];

        return [
            $card('attempted', ChartFormat::value($k['attempted']['value'], $format), $k['attempted']['delta']),
            $card('success', self::pct($k['success']['value']), $k['success']['delta'], true, 'points'),
            $card('recovered', ChartFormat::value($k['recovered']['value'], $format), $k['recovered']['delta']),
            $card('under', ChartFormat::value($k['under']['value'], $format), $k['under']['delta'], false),
            $card('lost', ChartFormat::value($k['lost']['value'], $format), $k['lost']['delta'], false),
        ];
    }

    /** @return array<string, mixed> rc.chart.combo props for the 12-month chart */
    private function monthly(array $q, string $format): array
    {
        $m = $q['monthly'];
        $locale = app()->getLocale();

        return [
            'labels' => array_map(static fn (string $ym): string => CarbonImmutable::parse($ym.'-01')->locale($locale)->translatedFormat('M y'), $m['months']),
            'bars' => [
                ['key' => 'realized', 'label' => __(self::LANG.'monthly.realized'), 'tone' => 's1', 'values' => $m['realized']],
                ['key' => 'under', 'label' => __(self::LANG.'monthly.under'), 'tone' => 's3', 'values' => $m['under']],
                ['key' => 'lost', 'label' => __(self::LANG.'monthly.lost'), 'tone' => 's4', 'values' => $m['lost']],
            ],
            'caps' => array_map(static fn (?float $s): string => $s === null ? '' : ChartFormat::value($s, ChartFormat::PERCENT), $m['success']),
            'format' => $format,
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, string>>} */
    private function sources(array $q, string $format): array
    {
        $dash = '—';
        $rows = array_map(static fn (array $s): array => [
            'source' => __(self::LANG.'source.'.$s['source']),
            'attempted' => ChartFormat::value($s['attempted'], $format),
            'realized' => ChartFormat::money($s['realized']),
            'under' => ChartFormat::value($s['under']),
            'realization' => self::pct($s['realization']) ?? $dash,
            'first_attempt' => self::pct($s['first_attempt']) ?? $dash,
            'first_cycle' => self::pct($s['first_cycle']) ?? $dash,
            'subsequent_cycle' => self::pct($s['subsequent_cycle']) ?? $dash,
        ], $q['sources']);

        $col = static fn (string $key, bool $numeric = true, bool $strong = false): array => [
            'key' => $key, 'label' => __(self::LANG.'sources.'.$key), 'numeric' => $numeric, 'strong' => $strong,
        ];

        return [
            'columns' => [
                $col('source', false, true), $col('attempted'), $col('realized'), $col('under'),
                $col('realization'), $col('first_attempt'), $col('first_cycle'), $col('subsequent_cycle'),
            ],
            'rows' => $rows,
            'shopify' => in_array(PaymentJourneys::SOURCE_SHOPIFY, array_column($q['sources'], 'source'), true),
        ];
    }
}
