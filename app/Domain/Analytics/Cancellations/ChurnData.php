<?php

namespace App\Domain\Analytics\Cancellations;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Subscribers\ActiveBook;
use App\Domain\Analytics\Subscribers\MovementLog;
use App\Domain\Analytics\Subscribers\MovementSummary;
use App\Domain\Analytics\Support\AnalyticsCache;
use Carbon\CarbonImmutable;

/**
 * The shared raw material of the Cancellations screens, cached per shop +
 * period + filters (AnalyticsCache):
 *
 *   summary($since)      — a MovementSummary over the movement log since $since
 *                          and today's active book: the SAME walker Subscribers ›
 *                          Overview uses, so churn rate / subscribers lost are
 *                          identical on both screens;
 *   cancellations(a, b)  — the CancellationLog rows between two moments.
 *
 * Nothing here formats or translates.
 */
final class ChurnData
{
    // === CONSTANTS ===
    public const CACHE_MOVEMENTS = 'cancellations.movements';

    public const CACHE_BOOK = 'cancellations.book';

    public const CACHE_LOG = 'cancellations.log';

    public function __construct(private readonly Context $context) {}

    public function summary(CarbonImmutable $since): MovementSummary
    {
        $period = $this->context->period;
        $filters = $this->context->filters;
        $tag = $since->format('Ymd');

        $rows = AnalyticsCache::remember(
            self::CACHE_MOVEMENTS, $period, $filters,
            fn (): array => (new MovementLog($filters))->since($since),
            $tag,
        );

        $book = AnalyticsCache::remember(self::CACHE_BOOK, $period, $filters, function () use ($filters, $rows): array {
            $book = new ActiveBook($filters);

            return [
                'totals' => $book->totals(),
                'per_customer' => $book->subscriptionsPerCustomer(array_column($rows, 'key')),
            ];
        }, $tag);

        return new MovementSummary($rows, $book['totals'], $book['per_customer']);
    }

    /** @return list<array<string, mixed>> */
    public function cancellations(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return AnalyticsCache::remember(
            self::CACHE_LOG, $this->context->period, $this->context->filters,
            fn (): array => (new CancellationLog($this->context->filters))->between($from, $to),
            $from->format('YmdHis').'-'.$to->format('YmdHis'),
        );
    }

    /**
     * The cancellations that COUNT in $window — the same rule as every movement
     * (MovementLog::countsIn): a payment-retry lapse recovered before the
     * window ends is not a cancellation of that window.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function inWindow(array $rows, Period $window): array
    {
        return MovementLog::inWindow($rows, $window);
    }

    /** A Period for an arbitrary [start, end] day span (custom range, no compare). */
    public static function span(CarbonImmutable $start, CarbonImmutable $end): Period
    {
        return Period::fromInput(Period::RANGE_CUSTOM, Period::COMPARE_NONE, $start->format(Period::DATE_FORMAT), $end->format(Period::DATE_FORMAT));
    }
}
