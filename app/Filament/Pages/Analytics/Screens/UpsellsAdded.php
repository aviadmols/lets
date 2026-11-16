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

    /** Over time: accepted in neutral gray, actually charged in the accent. */
    public const TONE_AGREED = 's6';

    public const TONE_CHARGED = 's1';

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

        return [
            'has_data' => $now['items'] > 0 || $sold['items'] > 0 || ($now['shown_orders'] ?? 0) > 0,
            'channel' => $channel,
            'channels' => self::channelOptions(self::LANG),
            'steps' => $this->steps($now),
            'kpis' => $this->kpis($now, $before, $caption),
            'trend' => $this->trend($now['by_day'], $sold['by_day'], $context),
            'offers' => $this->offers($now),
        ];
    }

    public function export(Context $context): ?array
    {
        $channel = $context->option(self::OPTION_CHANNEL, self::OPTIONS[self::OPTION_CHANNEL]);
        $offers = $this->offers((new UpsellsQuery($context, $channel))->added($context->period));

        return [
            'headers' => array_column($offers['columns'], 'label'),
            'rows' => array_map(static fn (array $r): array => array_map(
                static fn ($cell): string => is_array($cell) ? (string) ($cell['text'] ?? '') : (string) $cell,
                array_values($r),
            ), $offers['rows']),
        ];
    }

    /** Share as a percent, or null when there is nothing to divide by. */
    public static function rate(float|int $part, float|int|null $whole): ?float
    {
        return $whole ? Delta::share($part, $whole) : null;
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

    /**
     * The four boxes of the path: shown (orders) → accepted (orders) →
     * charged (money) → accepted but not charged (money).
     *
     * @return list<array{key: string, step: string, value: string, unit: string, rate: ?string}>
     */
    private function steps(array $now): array
    {
        $l = self::LANG.'.steps.';
        $pending = max(0.0, round($now['revenue'] - $now['sold_to_date'], 2));
        $accepted = self::rate($now['shown_accepted'], $now['shown_orders']);
        $charged = self::rate($now['sold_to_date'], $now['revenue']);

        return [
            ['key' => 'shown', 'step' => __($l.'shown.step'),
                'value' => $now['shown_orders'] === null ? '—' : ChartFormat::value($now['shown_orders']),
                'unit' => __($l.($now['shown_orders'] === null ? 'shown.untracked' : 'shown.unit')),
                'rate' => $now['shown_orders'] === null ? null : __($l.'shown.rate')],
            ['key' => 'accepted', 'step' => __($l.'accepted.step'), 'value' => ChartFormat::value($now['orders']), 'unit' => __($l.'accepted.unit'),
                'rate' => $accepted === null ? null : __($l.'accepted.rate', ['accepted' => ChartFormat::value($now['shown_accepted']), 'shown' => ChartFormat::value($now['shown_orders']), 'rate' => ChartFormat::value($accepted, ChartFormat::PERCENT)])],
            ['key' => 'charged', 'step' => __($l.'charged.step'), 'value' => ChartFormat::money($now['sold_to_date']), 'unit' => __($l.'charged.unit'),
                'rate' => $charged === null ? null : __($l.'charged.rate', ['rate' => ChartFormat::value($charged, ChartFormat::PERCENT)])],
            ['key' => 'pending', 'step' => __($l.'pending.step'), 'value' => ChartFormat::money($pending), 'unit' => __($l.'pending.unit'),
                'rate' => __($l.($pending > 0 ? 'pending.rate' : 'pending.none'))],
        ];
    }

    /** @return list<array<string, mixed>> rc.chart.kpi props + the formula line */
    private function kpis(array $now, ?array $before, string $caption): array
    {
        $l = self::LANG.'.kpi.';
        $avg = static fn (?array $a): ?float => $a === null || $a['orders'] === 0 ? null : round($a['revenue'] / $a['orders'], 2);
        $rate = static fn (?array $a): ?float => $a === null ? null : self::rate($a['shown_accepted'], $a['shown_orders']);
        $rateNow = $rate($now);
        $ratePrev = $rate($before);

        return [
            ['label' => __($l.'charged'), 'value' => ChartFormat::money($now['sold_to_date']), 'formula' => __($l.'charged_formula'),
                'delta' => Delta::percent($now['sold_to_date'], $before['sold_to_date'] ?? null), 'unit' => 'percent', 'compare' => $caption],
            ['label' => __($l.'avg'), 'value' => ($v = $avg($now)) === null ? null : ChartFormat::money($v), 'formula' => __($l.'avg_formula'),
                'delta' => Delta::percent($avg($now), $avg($before)), 'unit' => 'percent', 'compare' => __($l.'avg_note')],
            ['label' => __($l.'rate'), 'value' => $rateNow === null ? null : ChartFormat::value($rateNow, ChartFormat::PERCENT),
                'formula' => $now['shown_orders'] === null ? __($l.'rate_untracked') : __($l.'rate_formula'),
                'delta' => $rateNow !== null && $ratePrev !== null ? round($rateNow - $ratePrev, 1) : null, 'unit' => 'points', 'compare' => $caption],
        ];
    }

    /**
     * Accepted (valued when said) next to actually charged (money that moved), per bucket.
     *
     * @param  array<string, array<string, float>>  $agreed  channel => day => amount
     * @param  array<string, array<string, float>>  $charged  channel => day => amount
     * @return array<string, mixed>
     */
    private function trend(array $agreed, array $charged, Context $context): array
    {
        $period = $context->period;
        $grain = $context->grain(UpsellsQuery::CHART_ADDED, Granularity::WEEKLY);
        $sum = static function (array $byChannel): array {
            $out = [];
            foreach ($byChannel as $days) {
                foreach ($days as $day => $v) {
                    $out[$day] = ($out[$day] ?? 0.0) + $v;
                }
            }

            return $out;
        };
        $round = static fn (array $v): array => array_map(static fn (float $x): float => round($x, 2), $v);

        return [
            'id' => UpsellsQuery::CHART_ADDED,
            'labels' => array_column($grain->buckets($period), 'label'),
            'bars' => [
                ['key' => 'agreed', 'label' => __(self::LANG.'.trend.agreed'), 'tone' => self::TONE_AGREED, 'values' => $round($grain->rollUp($period, $sum($agreed)))],
                ['key' => 'charged', 'label' => __(self::LANG.'.trend.charged'), 'tone' => self::TONE_CHARGED, 'values' => $round($grain->rollUp($period, $sum($charged)))],
            ],
            'grain' => $grain->value,
            'grains' => SubscribersOverview::grainOptions($period),
            'partial' => self::lastBucketIsPartial($period, $grain),
        ];
    }

    /**
     * One table instead of two: every offer, from view to money.
     *
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    private function offers(array $added): array
    {
        $l = self::LANG.'.offers.';
        $items = $added['items_table'];
        usort($items, static fn (array $a, array $b): int => [$b['revenue'], $b['count']] <=> [$a['revenue'], $a['count']]);
        $orders = static fn (int $n): string => trans_choice($l.'orders', $n, ['count' => ChartFormat::value($n)]);

        return [
            'columns' => [
                ['key' => 'offer', 'label' => __($l.'offer'), 'strong' => true],
                ['key' => 'channel', 'label' => __($l.'channel')],
                ['key' => 'price', 'label' => __($l.'price'), 'numeric' => true],
                ['key' => 'shown', 'label' => __($l.'shown'), 'numeric' => true],
                ['key' => 'accepted', 'label' => __($l.'accepted'), 'numeric' => true],
                ['key' => 'rate', 'label' => __($l.'rate'), 'numeric' => true],
                ['key' => 'charged', 'label' => __($l.'charged'), 'numeric' => true],
                ['key' => 'pending', 'label' => __($l.'pending'), 'numeric' => true],
            ],
            'rows' => array_map(static function (array $i) use ($l, $orders): array {
                $rate = $i['shown'] === null ? null : self::rate($i['count'], $i['shown']);
                $pending = max(0.0, round($i['revenue'] - $i['charged'], 2));

                return [
                    'offer' => $i['title'] ?? __($l.'unnamed'),
                    'channel' => __(self::LANG.'.channel.'.$i['channel']),
                    'price' => ChartFormat::money($i['price']),
                    'shown' => $i['shown'] === null ? '—' : $orders($i['shown']),
                    'accepted' => $orders($i['count']),
                    'rate' => $rate === null ? '—' : ChartFormat::value($rate, ChartFormat::PERCENT),
                    'charged' => ChartFormat::money($i['charged']),
                    'pending' => $pending > 0 ? ['pill' => 'warn', 'text' => ChartFormat::money($pending)] : ChartFormat::money(0),
                ];
            }, $items),
        ];
    }
}
