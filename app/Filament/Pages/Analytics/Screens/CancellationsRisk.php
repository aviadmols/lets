<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Cancellations\RiskQuery;
use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Delta;
use App\Domain\Analytics\Support\Frequency;
use App\Livewire\Analytics\RiskTable;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Cancellations › Risk analysis (sketch: RiskAnalysis.dc.html; spec §6.4).
 *
 * Risk is a point-in-time judgement ("as of today"): the date range does not
 * change it. KPIs come from one SQL aggregate (RiskQuery::totals); the list is
 * the live RiskTable component — searchable, filterable, sortable, paginated,
 * exported as CSV. Scoring rules: RiskQuery's docblock.
 */
final class CancellationsRisk extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'RiskAnalysis.dc.html';

    public const VIEW = 'filament.pages.analytics.cancellations.risk';

    public const LANG = 'analytics/cancellations_risk.';

    public const FILTERS = [Filters::PLANS, Filters::FREQUENCIES];

    public const CACHE = 'cancellations.risk';

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $t = AnalyticsCache::remember(self::CACHE, $context->period, $context->filters,
            fn (): array => (new RiskQuery($context->filters))->totals());

        return [
            'kpis' => [
                [
                    'label' => __(self::LANG.'kpi.at_risk'),
                    'value' => ChartFormat::value($t['at_risk']),
                    'compare' => __(self::LANG.'caption.of_live', [
                        'share' => ChartFormat::value(Delta::share($t['at_risk'], $t['live']), ChartFormat::PERCENT),
                        'live' => ChartFormat::value($t['live']),
                    ]),
                ],
                ['label' => __(self::LANG.'kpi.high'), 'value' => ChartFormat::value($t['high']), 'compare' => __(self::LANG.'caption.high')],
                ['label' => __(self::LANG.'kpi.expiring'), 'value' => ChartFormat::value($t['expiring']), 'compare' => __(self::LANG.'caption.expiring', ['days' => RiskQuery::EXPIRING_DAYS])],
                [
                    'label' => __(self::LANG.'kpi.mrr'),
                    'value' => ChartFormat::money($t['mrr_at_risk']),
                    'compare' => __(self::LANG.'caption.mrr', ['share' => ChartFormat::value(Delta::share($t['mrr_at_risk'], $t['live_mrr']), ChartFormat::PERCENT)]),
                ],
            ],
            'table' => [
                'component' => RiskTable::class,
                'filters' => $context->filters->toArray(),
                'key' => 'risk-table-'.$context->filters->key(),
            ],
        ];
    }

    /** Every at-risk subscription, riskiest first (the table's own Export follows its search + filters). */
    public function export(Context $context): ?array
    {
        $rows = [];
        foreach ((new RiskQuery($context->filters))->all() as $r) {
            $rows[] = [
                $r['ref'] !== '' ? $r['ref'] : '#'.$r['id'],
                __(self::LANG.'level.'.RiskTable::LEVEL_KEYS[$r['risk']]),
                $r['status'],
                substr($r['created_at'], 0, 10),
                $r['name'],
                $r['email'],
                number_format($r['price'], 2, '.', ''),
                $r['orders'],
                $r['success'] === null ? '' : number_format($r['success'], 1, '.', ''),
                $r['streak'],
                __(self::LANG.'card.'.$r['card']),
                $r['card_exp'] ?? '',
                Frequency::label($r['freq']),
            ];
        }

        return [
            'headers' => array_map(static fn (string $k): string => __(self::LANG.'col.'.$k),
                ['subscription', 'risk', 'status', 'created', 'customer', 'email', 'price', 'orders', 'success', 'streak', 'card', 'expiry', 'interval']),
            'rows' => $rows,
        ];
    }
}
