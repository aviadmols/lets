<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Cancellations\SavesQuery;
use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Support\Delta;
use App\Support\Ui\Charts\ChartFormat;
use Carbon\CarbonImmutable;

/**
 * Cancellations › Saves (sketch: Saves.dc.html; spec §6.2).
 *
 * LETS has no cancellation flow, so save rate, saved MRR, every retention
 * tool and every per-reason / per-offer table render the not-tracked state
 * (docs/analytics/data-map.md §6 ✖) — with the one line of what would have to
 * exist. What IS recorded is drawn for real (SavesQuery): lapsed subscriptions
 * that came back after a failed payment, and people who cancelled and later
 * subscribed again.
 */
final class CancellationsSaves extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'Saves.dc.html';

    public const VIEW = 'filament.pages.analytics.cancellations.saves';

    public const LANG = 'analytics/cancellations_saves.';

    public const FILTERS = [Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public const TONES = [SavesQuery::TYPE_REACTIVATED => 's2', SavesQuery::TYPE_RETURNED => 's5'];

    public const PILLS = [SavesQuery::TYPE_REACTIVATED => 'good', SavesQuery::TYPE_RETURNED => 'info'];

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $q = (new SavesQuery($context))->get();
        $t = $q['totals'];
        $p = $q['previous'];

        $bars = [];
        foreach ([SavesQuery::TYPE_REACTIVATED, SavesQuery::TYPE_RETURNED] as $type) {
            $bars[] = ['key' => $type, 'label' => __(self::LANG.'type.'.$type), 'tone' => self::TONES[$type], 'values' => $q['series']['values'][$type]];
        }

        return [
            'has_data' => $q['has_data'],
            'kpis' => [
                ['label' => __(self::LANG.'kpi.save_rate'), 'value' => null, 'empty' => 'not_tracked'],
                ['label' => __(self::LANG.'kpi.saved_mrr'), 'value' => null, 'empty' => 'not_tracked'],
                [
                    'label' => __(self::LANG.'kpi.reactivated'),
                    'value' => ChartFormat::value($t['reactivated']),
                    'delta' => $p ? Delta::percent($t['reactivated'], $p['reactivated']) : null,
                    'compare' => __(self::LANG.'caption.mrr_back', ['mrr' => ChartFormat::money($t['reactivated_mrr'])]),
                ],
                [
                    'label' => __(self::LANG.'kpi.returned'),
                    'value' => ChartFormat::value($t['returned']),
                    'delta' => $p ? Delta::percent($t['returned'], $p['returned']) : null,
                    'compare' => __(self::LANG.'caption.vs_cancelled', ['n' => ChartFormat::value($q['cancelled'])]),
                ],
            ],
            'compare' => $context->period->label(),
            'chart' => [
                'id' => SavesQuery::CHART,
                'labels' => $q['series']['labels'],
                'bars' => $bars,
                'grain' => $q['grain']->value,
                'grains' => SubscribersOverview::grainOptions($context->period),
            ],
            'table' => $this->table($q['recent']),
        ];
    }

    public function export(Context $context): ?array
    {
        $rows = (new SavesQuery($context))->get(SavesQuery::EXPORT_MAX)['recent'];

        return [
            'headers' => array_map(static fn (string $k): string => __(self::LANG.'table.'.$k), ['date', 'subscription', 'customer', 'type', 'mrr']),
            'rows' => array_map(static fn (array $r): array => [
                substr($r['at'], 0, 16), $r['ref'], $r['name'], __(self::LANG.'type.'.$r['type']), number_format($r['mrr'], 2, '.', ''),
            ], $rows),
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>} */
    private function table(array $rows): array
    {
        $locale = app()->getLocale();

        return [
            'columns' => [
                ['key' => 'date', 'label' => __(self::LANG.'table.date')],
                ['key' => 'subscription', 'label' => __(self::LANG.'table.subscription'), 'strong' => true],
                ['key' => 'customer', 'label' => __(self::LANG.'table.customer')],
                ['key' => 'type', 'label' => __(self::LANG.'table.type')],
                ['key' => 'mrr', 'label' => __(self::LANG.'table.mrr'), 'numeric' => true],
            ],
            'rows' => array_map(static fn (array $r): array => [
                'date' => CarbonImmutable::parse($r['at'])->locale($locale)->translatedFormat('j M Y'),
                'subscription' => $r['ref'],
                'customer' => $r['name'] !== '' ? $r['name'] : '—',
                'type' => ['pill' => self::PILLS[$r['type']], 'text' => __(self::LANG.'type.'.$r['type'])],
                'mrr' => ChartFormat::money($r['mrr']),
            ], $rows),
        ];
    }
}
