<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Support\Delta;
use App\Domain\Analytics\Upsells\UpsellsQuery;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Upsells › Sold (spec §5.2; drawn in the Upsells.dc.html vocabulary — the
 * board shows the Added tab). Money that actually moved, net of refunds:
 * after-purchase charges on the saved PayPlus token, and add-ons bought in the
 * account area. Shopify's post-purchase step is charged by Shopify and never
 * confirmed back to LETS, so it counts as added, never sold — the note says so.
 */
final class UpsellsSold extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'Upsells.dc.html';

    public const VIEW = 'filament.pages.analytics.upsells.sold';

    public const LANG = 'analytics/upsells_sold';

    public const FILTERS = [];

    public const OPTION_CHANNEL = 'channel';

    public const OPTIONS = [self::OPTION_CHANNEL => UpsellsQuery::CHANNEL_OPTIONS];

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $channel = $context->option(self::OPTION_CHANNEL, self::OPTIONS[self::OPTION_CHANNEL]);
        $query = new UpsellsQuery($context, $channel);
        $period = $context->period;
        $compare = $period->comparison();
        $now = $query->sold($period);
        $before = $compare ? $query->sold($compare) : null;
        $caption = SubscribersOverview::compareCaption($period);
        $avg = static fn (?array $s): ?float => $s === null || $s['orders'] === 0 ? null : round($s['revenue'] / $s['orders'], 2);

        return [
            'has_data' => $now['items'] > 0,
            'channel' => $channel,
            'channels' => UpsellsAdded::channelOptions(self::LANG),
            'kpis' => [
                ['label' => __(self::LANG.'.kpi.items'), 'value' => ChartFormat::value($now['items']), 'delta' => Delta::percent($now['items'], $before['items'] ?? null), 'compare' => $caption],
                ['label' => __(self::LANG.'.kpi.revenue'), 'value' => ChartFormat::money($now['revenue']), 'delta' => Delta::percent($now['revenue'], $before['revenue'] ?? null), 'compare' => $caption],
                ['label' => __(self::LANG.'.kpi.avg'), 'value' => ($v = $avg($now)) === null ? null : ChartFormat::money($v), 'delta' => Delta::percent($avg($now), $avg($before)), 'compare' => $caption],
                ['label' => __(self::LANG.'.kpi.contribution'), 'value' => $now['contribution'] === null ? null : ChartFormat::value($now['contribution'], ChartFormat::PERCENT),
                    'delta' => Delta::points($now['contribution'], $before['contribution'] ?? null), 'unit' => Delta::UNIT_POINTS, 'compare' => $caption],
            ],
            'performance' => UpsellsAdded::performance($now['by_day'], $context, UpsellsQuery::CHART_SOLD, self::LANG.'.channel'),
            'performance_total' => ChartFormat::money($now['revenue']),
            'centre' => ChartFormat::money(round($now['revenue'])), // whole shekels fit the ring
            'by_channel' => UpsellsAdded::channelSlices($now['by_day'], self::LANG.'.channel'),
            'items' => $this->items($now),
            'collected' => ChartFormat::money($now['collected']),
        ];
    }

    public function export(Context $context): ?array
    {
        $channel = $context->option(self::OPTION_CHANNEL, self::OPTIONS[self::OPTION_CHANNEL]);
        $items = $this->items((new UpsellsQuery($context, $channel))->sold($context->period));

        return [
            'headers' => array_column($items['columns'], 'label'),
            'rows' => array_map(static fn (array $r): array => array_values($r), $items['rows']),
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, string>>} */
    private function items(array $sold): array
    {
        $l = self::LANG.'.items.';
        $items = $sold['items_table'];
        usort($items, static fn (array $a, array $b): int => [$b['revenue'], $b['count']] <=> [$a['revenue'], $a['count']]);
        $total = max(0.01, $sold['revenue']);

        return [
            'columns' => [
                ['key' => 'item', 'label' => __($l.'item'), 'strong' => true],
                ['key' => 'channel', 'label' => __($l.'channel')],
                ['key' => 'sold', 'label' => __($l.'sold'), 'numeric' => true],
                ['key' => 'revenue', 'label' => __($l.'revenue'), 'numeric' => true],
                ['key' => 'avg', 'label' => __($l.'avg'), 'numeric' => true],
                ['key' => 'share', 'label' => __($l.'share'), 'numeric' => true],
            ],
            'rows' => array_map(static fn (array $i): array => [
                'item' => $i['title'] ?? __($l.'unnamed'),
                'channel' => __(self::LANG.'.channel.'.$i['channel']),
                'sold' => ChartFormat::value($i['count']),
                'revenue' => ChartFormat::money($i['revenue']),
                'avg' => $i['count'] > 0 ? ChartFormat::money(round($i['revenue'] / $i['count'], 2)) : '—',
                'share' => ChartFormat::value(Delta::share($i['revenue'], $total), ChartFormat::PERCENT),
            ], $items),
        ];
    }
}
