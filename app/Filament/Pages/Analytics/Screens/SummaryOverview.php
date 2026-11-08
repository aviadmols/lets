<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Products\ProductData;
use App\Domain\Analytics\Subscribers\MovementLog;
use App\Domain\Analytics\Subscribers\MovementSummary;
use App\Domain\Analytics\Subscribers\SubscribersOverviewQuery;
use App\Domain\Analytics\Support\Delta;
use App\Filament\Pages\PaymentRecovery;
use App\Models\InstallmentPlan;
use App\Support\Ui\Charts\ChartFormat;

/**
 * Summary — the plain-language screen a merchant opens first.
 *
 * Five questions, in the order a merchant asks them, and nothing else:
 *   1. how many subscriptions are active right now (and of which kind);
 *   2. how much they bring in a month (MRR);
 *   3. how many joined in the chosen period — and when (by day / week / month);
 *   4. how many left in the chosen period — and when;
 *   5. how many are not paying (collection problems).
 *
 * ONE unit throughout: a subscription. The by-product rows add up to the active
 * total, the joined/left counts are counts of subscriptions, so no number on the
 * screen needs a footnote to reconcile with another. (The detailed Subscribers
 * screens keep the people / subscriptions split for whoever needs it.)
 *
 * Reuses the existing queries — SubscribersOverviewQuery for the active book and
 * movements, ProductData for the per-product split, PaymentRecovery's own scopes
 * for collection problems — so this screen can never disagree with the detailed
 * ones. No filter chips: it reads the whole shop.
 */
final class SummaryOverview extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const VIEW = 'filament.pages.analytics.summary.overview';

    public const FILTERS = [];

    /** The quick period buttons above the screen, in order. */
    public const QUICK_RANGES = [
        Period::RANGE_TODAY,
        Period::RANGE_7,
        Period::RANGE_30,
        Period::RANGE_MTD,
        Period::RANGE_LAST_MONTH,
        Period::RANGE_YTD,
        Period::RANGE_365,
    ];

    /** Movement types that count as "joined" and as "left". */
    public const JOINED = [MovementLog::NEW, MovementLog::REACTIVATED];

    public const LEFT = [MovementLog::CANCELLED, MovementLog::EXPIRED];

    /** The trend chart id — shares the subscriptions chart's granularity key. */
    public const CHART = SubscribersOverviewQuery::CHART_SUBSCRIPTIONS;

    private const LEVEL = MovementSummary::LEVEL_SUBSCRIPTION;

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        $context = $context->withoutFilters();
        $q = (new SubscribersOverviewQuery($context))->get();
        $compare = SubscribersOverview::compareCaption($context->period);

        $activity = $q['activity'][self::LEVEL];
        $counts = $activity['counts'];
        $previous = $activity['previous'];

        $joined = $this->sum($counts, self::JOINED);
        $left = $this->sum($counts, self::LEFT);
        $prevJoined = $previous !== null ? $this->sum($previous, self::JOINED) : null;
        $prevLeft = $previous !== null ? $this->sum($previous, self::LEFT) : null;

        $series = $q['series'][self::CHART];
        $active = $q['kpis']['subscriptions'];

        return [
            'has_data' => $q['has_data'],
            'quick_ranges' => self::QUICK_RANGES,
            'period_label' => $context->period->label(),
            'compare' => $compare,

            'active' => ChartFormat::value($active['value']),
            'active_delta' => $active['delta'],
            'customers' => ChartFormat::value($q['kpis']['subscribers']['value']),
            'mrr' => ChartFormat::money((float) $q['kpis']['mrr']['value']),
            'mrr_delta' => $q['kpis']['mrr']['delta'],

            'joined' => ChartFormat::value($joined),
            'joined_delta' => Delta::percent($joined, $prevJoined),
            'joined_new' => ChartFormat::value($counts[MovementLog::NEW]),
            'joined_returned' => ChartFormat::value($counts[MovementLog::REACTIVATED]),

            'left' => ChartFormat::value($left),
            'left_delta' => Delta::percent($left, $prevLeft),
            'left_cancelled' => ChartFormat::value($counts[MovementLog::CANCELLED]),
            'left_expired' => ChartFormat::value($counts[MovementLog::EXPIRED]),
            'paused' => ChartFormat::value($counts[MovementLog::PAUSED]),
            'net' => ChartFormat::signed($joined - $left),
            'net_tone' => $joined > $left ? 'good' : ($joined < $left ? 'bad' : null),

            'by_type' => $this->byType($context, (int) $active['value']),

            'grain' => SubscribersOverviewQuery::grain($context, self::CHART)->value,
            'grains' => SubscribersOverview::grainOptions($context->period),
            'chart_id' => self::CHART,
            'joined_chart' => $this->bars($series, self::JOINED, 'joined', 's1'),
            'left_chart' => $this->bars($series, self::LEFT, 'left', 's4'),
            'active_chart' => [
                'labels' => $series['labels'],
                'series' => [[
                    'key' => 'active',
                    'label' => __('analytics/summary_overview.trend.active'),
                    'tone' => 'ink',
                    'values' => $series['active'],
                    'area' => true,
                ]],
            ],

            'collection' => $this->collection(),
        ];
    }

    // === Shaping ===

    /** @param array<string, int> $counts  @param list<string> $types */
    private function sum(array $counts, array $types): int
    {
        return array_sum(array_intersect_key($counts, array_flip($types)));
    }

    /**
     * One bar series: the movement types summed per bucket.
     *
     * @param  array<string, mixed>  $series  MovementSummary::series() shape
     * @param  list<string>  $types
     * @return array{labels: list<string>, bars: list<array<string, mixed>>}
     */
    private function bars(array $series, array $types, string $key, string $tone): array
    {
        $values = array_fill(0, count($series['labels']), 0);
        foreach ($types as $type) {
            foreach ($series['types'][$type] ?? [] as $i => $n) {
                $values[$i] += (int) $n;
            }
        }

        return [
            'labels' => $series['labels'],
            'bars' => [[
                'key' => $key,
                'label' => __('analytics/summary_overview.trend.'.$key),
                'tone' => $tone,
                'values' => $values,
            ]],
        ];
    }

    /**
     * Active subscriptions per product (digital / regular / Shibolet, as the
     * shop named them), biggest first, with each one's monthly revenue and share.
     *
     * @return list<array<string, string>>
     */
    private function byType(Context $context, int $total): array
    {
        $data = new ProductData($context);
        $book = $data->book()['by_product'];
        $names = $data->names(array_map(static fn (array $r): ?string => $r['title'], $book));

        uasort($book, static fn (array $a, array $b): int => $b['subscriptions'] <=> $a['subscriptions']);

        $rows = [];
        foreach ($book as $pk => $r) {
            $rows[] = [
                'type' => (string) ($names[$pk] ?? $r['title'] ?? __('analytics/summary_overview.by_type.unknown')),
                'active' => ChartFormat::value($r['subscriptions']),
                'mrr' => ChartFormat::money((float) $r['mrr']),
                'share' => ChartFormat::value(Delta::share($r['subscriptions'], max(1, $total)), ChartFormat::PERCENT),
            ];
        }

        return $rows;
    }

    /**
     * Subscriptions with a collection problem, counted exactly as the Payment
     * recovery screen counts them (its own scopes), with a link there.
     *
     * @return array{retrying: string, stopped: string, total: int, url: string}
     */
    private function collection(): array
    {
        $retrying = PaymentRecovery::scopeFor(PaymentRecovery::TAB_RETRYING, InstallmentPlan::query())->count();
        $stopped = PaymentRecovery::scopeFor(PaymentRecovery::TAB_STOPPED, InstallmentPlan::query())->count();

        return [
            'retrying' => ChartFormat::value($retrying),
            'stopped' => ChartFormat::value($stopped),
            'total' => $retrying + $stopped,
            'url' => PaymentRecovery::getUrl(),
        ];
    }
}
