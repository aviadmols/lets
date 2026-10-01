<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Support\Delta;
use App\Domain\Analytics\Upsells\UpsellsQuery;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Upsells › Added (sketch Upsells.dc.html; spec §5.1). UpsellsQuery computes;
 * this class shapes; the partial draws.
 *
 * Channels are what LETS records: the after-purchase offer (thank-you page /
 * Shopify post-purchase step) and the customer's account area. Admin-added
 * extras and campaign upsells have no source yet — said in a note, never drawn
 * as zero. "Upsell profiles" are the upsell flows (and account offers, which
 * record no impressions). The product chips do not apply to upsells (an offer
 * is not a subscription), so this screen offers none.
 */
final class UpsellsAdded extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'Upsells.dc.html';

    public const VIEW = 'filament.pages.analytics.upsells.added';

    public const LANG = 'analytics/upsells_added';

    public const FILTERS = [];

    public const OPTION_CHANNEL = 'channel';

    public const OPTIONS = [self::OPTION_CHANNEL => UpsellsQuery::CHANNEL_OPTIONS];

    /** Channel tones (both are additions: accent + teal). */
    public const CHANNEL_TONES = [UpsellsQuery::CHANNEL_CHECKOUT => 's1', UpsellsQuery::CHANNEL_ACCOUNT => 's2'];

    /** Item rows shown before "All other items". */
    public const TOP_ITEMS = 5;

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
        $now = $query->added($period);
        $before = $compare ? $query->added($compare) : null;
        $sold = $query->sold($period);
        $caption = SubscribersOverview::compareCaption($period);

        $avg = static fn (?array $a): ?float => $a === null ? null : ($a['orders'] > 0 ? round($a['revenue'] / $a['orders'], 2) : null);
        $soldShare = $now['revenue'] > 0 ? Delta::share($now['sold_to_date'], $now['revenue']) : null;

        return [
            'has_data' => $now['items'] > 0 || $sold['items'] > 0,
            'channel' => $channel,
            'channels' => self::channelOptions(self::LANG),
            'kpis' => [
                ['label' => __(self::LANG.'.kpi.items'), 'value' => ChartFormat::value($now['items']), 'delta' => Delta::percent($now['items'], $before['items'] ?? null), 'compare' => $caption],
                ['label' => __(self::LANG.'.kpi.revenue'), 'value' => ChartFormat::money($now['revenue']), 'delta' => Delta::percent($now['revenue'], $before['revenue'] ?? null), 'compare' => $caption],
                ['label' => __(self::LANG.'.kpi.avg'), 'value' => ($v = $avg($now)) === null ? null : ChartFormat::money($v), 'delta' => Delta::percent($avg($now), $avg($before)), 'compare' => $caption],
                ['label' => __(self::LANG.'.kpi.sold'), 'value' => ChartFormat::money($now['sold_to_date']), 'delta' => Delta::percent($now['sold_to_date'], $before['sold_to_date'] ?? null),
                    'compare' => $soldShare === null ? $caption : __(self::LANG.'.kpi.sold_share', ['share' => ChartFormat::value($soldShare, ChartFormat::PERCENT)])],
            ],
            'performance' => self::performance($now['by_day'], $context, UpsellsQuery::CHART_ADDED, self::LANG.'.channel'),
            'performance_total' => ChartFormat::money($now['revenue']),
            'centre' => ChartFormat::money(round($now['revenue'])), // whole shekels fit the ring
            'by_channel' => self::channelSlices($now['by_day'], self::LANG.'.channel'),
            'items' => $this->items($now),
            'profiles' => $this->profiles($now['profiles']),
            'sold' => [
                ['label' => __(self::LANG.'.sold.items'), 'value' => ChartFormat::value($sold['items'])],
                ['label' => __(self::LANG.'.sold.revenue'), 'value' => ChartFormat::money($sold['revenue'])],
                ['label' => __(self::LANG.'.sold.avg'), 'value' => $sold['orders'] > 0 ? ChartFormat::money(round($sold['revenue'] / $sold['orders'], 2)) : '—'],
                ['label' => __(self::LANG.'.sold.contribution'), 'value' => ChartFormat::value($sold['contribution'], ChartFormat::PERCENT)],
            ],
        ];
    }

    public function export(Context $context): ?array
    {
        $channel = $context->option(self::OPTION_CHANNEL, self::OPTIONS[self::OPTION_CHANNEL]);
        $items = $this->items((new UpsellsQuery($context, $channel))->added($context->period), false);

        return [
            'headers' => array_column($items['columns'], 'label'),
            'rows' => array_map(static fn (array $r): array => array_values($r), $items['rows']),
        ];
    }

    // === Shared with UpsellsSold ===

    /** @return array<string, string> channel option => label */
    public static function channelOptions(string $lang): array
    {
        $out = [];
        foreach (UpsellsQuery::CHANNEL_OPTIONS as $channel) {
            $out[$channel] = __($lang.'.channel.'.$channel);
        }

        return $out;
    }

    /**
     * Revenue per bucket, one stacked series per channel present.
     *
     * @param  array<string, array<string, float>>  $byDay  channel => day => revenue
     * @return array<string, mixed>
     */
    public static function performance(array $byDay, Context $context, string $chartId, string $channelLang): array
    {
        $period = $context->period;
        $grain = $context->grain($chartId, Granularity::WEEKLY);
        $bars = [];
        foreach (UpsellsQuery::CHANNELS as $channel) {
            if (! isset($byDay[$channel])) {
                continue;
            }
            $bars[] = [
                'key' => $channel,
                'label' => __($channelLang.'.'.$channel),
                'tone' => self::CHANNEL_TONES[$channel],
                'values' => array_map(static fn (float $v): float => round($v, 2), $grain->rollUp($period, $byDay[$channel])),
            ];
        }

        return [
            'id' => $chartId,
            'labels' => array_column($grain->buckets($period), 'label'),
            'bars' => $bars,
            'grain' => $grain->value,
            'grains' => SubscribersOverview::grainOptions($period),
            'partial' => self::lastBucketIsPartial($period, $grain),
        ];
    }

    /** True when the window ends before its last bucket would (a week or month in progress). */
    public static function lastBucketIsPartial(Period $period, Granularity $grain): bool
    {
        $end = $period->end()->startOfDay();

        return match ($grain) {
            Granularity::DAILY => false,
            Granularity::WEEKLY => ! $end->isSunday(),
            Granularity::MONTHLY => ! $end->isLastOfMonth(),
        };
    }

    /**
     * @param  array<string, array<string, float>>  $byDay
     * @return list<array<string, mixed>>
     */
    public static function channelSlices(array $byDay, string $channelLang): array
    {
        $out = [];
        foreach (UpsellsQuery::CHANNELS as $channel) {
            $total = round(array_sum($byDay[$channel] ?? []), 2);
            if ($total > 0) {
                $out[] = ['label' => __($channelLang.'.'.$channel), 'value' => $total, 'tone' => self::CHANNEL_TONES[$channel], 'display' => ChartFormat::money($total)];
            }
        }

        return $out;
    }

    // === Shaping ===

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, string>>} */
    private function items(array $added, bool $fold = true): array
    {
        $l = self::LANG.'.items.';
        $items = $added['items_table'];
        usort($items, static fn (array $a, array $b): int => [$b['count'], $b['revenue']] <=> [$a['count'], $a['revenue']]);
        $total = max(0.01, $added['revenue']);
        $top = $fold ? array_slice($items, 0, self::TOP_ITEMS) : $items;
        $rest = $fold ? array_slice($items, self::TOP_ITEMS) : [];

        $row = static fn (string $title, string $channel, string $price, int $count, float $revenue): array => [
            'item' => $title,
            'channel' => $channel,
            'price' => $price,
            'added' => ChartFormat::value($count),
            'revenue' => ChartFormat::money($revenue),
            'share' => ChartFormat::value(Delta::share($revenue, $total), ChartFormat::PERCENT),
        ];
        $rows = array_map(static fn (array $i): array => $row(
            $i['title'] ?? __($l.'unnamed'),
            __(self::LANG.'.channel.'.$i['channel']),
            ChartFormat::money($i['price']),
            $i['count'],
            $i['revenue'],
        ), $top);
        if ($rest !== []) {
            $rows[] = $row(
                trans_choice($l.'others', count($rest), ['count' => count($rest)]),
                '—', '—',
                (int) array_sum(array_column($rest, 'count')),
                (float) array_sum(array_column($rest, 'revenue')),
            );
        }

        return [
            'columns' => [
                ['key' => 'item', 'label' => __($l.'item'), 'strong' => true],
                ['key' => 'channel', 'label' => __($l.'channel')],
                ['key' => 'price', 'label' => __($l.'price'), 'numeric' => true],
                ['key' => 'added', 'label' => __($l.'added'), 'numeric' => true],
                ['key' => 'revenue', 'label' => __($l.'revenue'), 'numeric' => true],
                ['key' => 'share', 'label' => __($l.'share'), 'numeric' => true],
            ],
            'rows' => $rows,
            'count' => ChartFormat::value($added['items']),
        ];
    }

    /** @return array{columns: list<array<string, mixed>>, rows: list<array<string, string>>} */
    private function profiles(array $profiles): array
    {
        $l = self::LANG.'.profiles.';
        usort($profiles, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return [
            'columns' => [
                ['key' => 'profile', 'label' => __($l.'profile'), 'strong' => true],
                ['key' => 'shown', 'label' => __($l.'shown'), 'numeric' => true],
                ['key' => 'accepted', 'label' => __($l.'accepted'), 'numeric' => true],
                ['key' => 'rate', 'label' => __($l.'rate'), 'numeric' => true],
                ['key' => 'revenue', 'label' => __($l.'revenue'), 'numeric' => true],
            ],
            'rows' => array_map(static fn (array $p): array => [
                'profile' => $p['name'] ?? __($l.'unnamed', ['id' => $p['id']]),
                'shown' => $p['shown'] === null ? '—' : ChartFormat::value($p['shown']),
                'accepted' => ChartFormat::value($p['accepted']),
                'rate' => $p['shown'] ? ChartFormat::value(Delta::share($p['accepted'], $p['shown']), ChartFormat::PERCENT) : '—',
                'revenue' => ChartFormat::money($p['revenue']),
            ], $profiles),
        ];
    }
}
