<?php

namespace App\Domain\Analytics\Subscribers;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Delta;

/**
 * Subscribers › Overview — the numbers, nothing else. The screen class turns
 * this into charts; this class never formats or translates.
 *
 * Two SQL-heavy inputs are cached per shop + period + filters for a few
 * minutes (AnalyticsCache): today's ActiveBook (totals + breakdowns + the
 * per-customer counts the walker needs) and the MovementLog since the earliest
 * moment the period or its comparison reads. Everything after that is PHP
 * over a few hundred movement rows (MovementSummary), so flipping a chart's
 * granularity never re-queries.
 */
final class SubscribersOverviewQuery
{
    // === CONSTANTS ===
    public const CACHE_BOOK = 'subscribers.book';

    public const CACHE_MOVEMENTS = 'subscribers.movements';

    /** Chart ids — the keys of the per-chart granularity in the URL. */
    public const CHART_SUBSCRIBERS = 'subscribers_trend';

    public const CHART_SUBSCRIPTIONS = 'subscriptions_trend';

    public const CHART_CANCELLED_MRR = 'cancelled_mrr';

    /** Charts whose sketch default is not the period default (the sketch draws these weekly). */
    public const PREFERRED_GRAIN = [
        self::CHART_SUBSCRIPTIONS => Granularity::WEEKLY,
        self::CHART_CANCELLED_MRR => Granularity::WEEKLY,
    ];

    public function __construct(private readonly Context $context) {}

    /** The granularity a chart of this screen is drawn at. */
    public static function grain(Context $context, string $chartId): Granularity
    {
        return $context->grain($chartId, self::PREFERRED_GRAIN[$chartId] ?? null);
    }

    /**
     * @return array{
     *   kpis: array<string, array{value: float|int, previous: float|int|null, delta: ?float}>,
     *   activity: array<string, array<string, mixed>>,
     *   series: array<string, array<string, mixed>>,
     *   by_frequency: list<array<string, mixed>>,
     *   by_plan: list<array<string, mixed>>,
     *   plan_frequency: list<array<string, mixed>>,
     *   quantities: array<string, int>,
     *   churn: array<string, mixed>,
     *   churn_previous: ?array<string, mixed>,
     *   has_data: bool
     * }
     */
    public function get(): array
    {
        $period = $this->context->period;
        $compare = $period->comparison();
        $book = $this->book();
        $rows = $this->movements();

        $summary = new MovementSummary($rows, $book['totals'], $book['per_customer']);

        $end = $period->end();
        $compareEnd = $compare?->end();

        $kpi = static fn (float|int $value, float|int|null $previous): array => [
            'value' => $value,
            'previous' => $previous,
            'delta' => Delta::percent($value, $previous),
        ];

        $kpis = [
            'subscribers' => $kpi(
                $summary->activeAt(MovementSummary::LEVEL_SUBSCRIBER, $end),
                $compareEnd ? $summary->activeAt(MovementSummary::LEVEL_SUBSCRIBER, $compareEnd) : null,
            ),
            'subscriptions' => $kpi(
                $summary->activeAt(MovementSummary::LEVEL_SUBSCRIPTION, $end),
                $compareEnd ? $summary->activeAt(MovementSummary::LEVEL_SUBSCRIPTION, $compareEnd) : null,
            ),
            'quantity' => $kpi($summary->quantityAt($end), $compareEnd ? $summary->quantityAt($compareEnd) : null),
            'mrr' => $kpi($summary->mrrAt($end), $compareEnd ? $summary->mrrAt($compareEnd) : null),
        ];

        $activity = [];
        foreach ([MovementSummary::LEVEL_SUBSCRIBER, MovementSummary::LEVEL_SUBSCRIPTION] as $level) {
            $counts = $summary->counts($level, $period);
            $previous = $compare ? $summary->counts($level, $compare) : null;
            $activity[$level] = [
                'active' => $kpis[$level === MovementSummary::LEVEL_SUBSCRIBER ? 'subscribers' : 'subscriptions'],
                'counts' => $counts,
                'previous' => $previous,
                'additions' => array_sum(array_intersect_key($counts, array_flip(MovementLog::ADDITIONS))),
                'reductions' => array_sum(array_intersect_key($counts, array_flip(MovementLog::REDUCTIONS))),
                'previous_additions' => $previous ? array_sum(array_intersect_key($previous, array_flip(MovementLog::ADDITIONS))) : null,
                'previous_reductions' => $previous ? array_sum(array_intersect_key($previous, array_flip(MovementLog::REDUCTIONS))) : null,
            ];
        }

        $churn = $summary->churn($period);

        return [
            'kpis' => $kpis,
            'activity' => $activity,
            'series' => [
                self::CHART_SUBSCRIBERS => $summary->series(MovementSummary::LEVEL_SUBSCRIBER, $period, self::grain($this->context, self::CHART_SUBSCRIBERS)),
                self::CHART_SUBSCRIPTIONS => $summary->series(MovementSummary::LEVEL_SUBSCRIPTION, $period, self::grain($this->context, self::CHART_SUBSCRIPTIONS)),
                self::CHART_CANCELLED_MRR => $summary->cancelledMrrSeries($period, self::grain($this->context, self::CHART_CANCELLED_MRR)),
            ],
            'by_frequency' => $book['by_frequency'],
            'by_plan' => $book['by_plan'],
            'plan_frequency' => $book['plan_frequency'],
            'quantities' => $summary->quantities($period),
            'churn' => $churn,
            'churn_previous' => $compare ? $summary->churn($compare) : null,
            'has_data' => $book['totals']['subscriptions'] > 0 || $rows !== [],
        ];
    }

    /** @return array{totals: array<string, int|float>, by_frequency: list<array<string, mixed>>, by_plan: list<array<string, mixed>>, plan_frequency: list<array<string, mixed>>, per_customer: array<string, int>} */
    private function book(): array
    {
        $filters = $this->context->filters;

        return AnalyticsCache::remember(self::CACHE_BOOK, $this->context->period, $filters, function () use ($filters): array {
            $book = new ActiveBook($filters);
            $rows = $this->movements();

            return [
                'totals' => $book->totals(),
                'by_frequency' => $book->byFrequency(),
                'by_plan' => $book->bySellingPlan(),
                'plan_frequency' => $book->planByFrequency(),
                'per_customer' => $book->subscriptionsPerCustomer(array_column($rows, 'key')),
            ];
        });
    }

    /** @return list<array<string, mixed>> */
    private function movements(): array
    {
        $period = $this->context->period;
        $filters = $this->context->filters;

        return AnalyticsCache::remember(
            self::CACHE_MOVEMENTS,
            $period,
            $filters,
            fn (): array => (new MovementLog($filters))->since($period->earliest()),
        );
    }
}
