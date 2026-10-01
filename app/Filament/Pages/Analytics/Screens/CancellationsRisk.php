<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Cancellations\RiskQuery;
use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Support\Delta;
use App\Livewire\Analytics\RiskTable;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Cancellations › Risk analysis (sketch: RiskAnalysis.dc.html; spec §6.4).
 *
 * Risk is a point-in-time judgement ("as of today"): the date range does not
 * change it. KPIs come from the cached scored book (RiskQuery::totals); the list is
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

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $t = (new RiskQuery($context->filters))->totals(); // cached inside RiskQuery (one scored book per shop + filters)

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
            $rows[] = RiskTable::csvRow($r);
        }

        return ['headers' => RiskTable::csvHeaders(), 'rows' => $rows];
    }
}
