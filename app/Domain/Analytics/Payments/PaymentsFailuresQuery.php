<?php

namespace App\Domain\Analytics\Payments;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Support\Delta;

/**
 * Payments › Failures — the numbers (spec §3.3): failed FIRST attempts in the
 * period (both rails), what became of them, the split by payment source and
 * by decline reason (DeclineReason buckets over PayPlus's free-text message),
 * and the reasons over time.
 */
final class PaymentsFailuresQuery
{
    // === CONSTANTS ===
    public const CHART_OVER_TIME = 'failures_over_time';

    public function __construct(private readonly Context $context) {}

    public static function grain(Context $context): Granularity
    {
        return $context->grain(self::CHART_OVER_TIME, Granularity::WEEKLY);
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $period = $this->context->period;
        $compare = $period->comparison();
        $all = new JourneyStats(PaymentJourneys::for($period, $this->context->filters));
        $journeys = $all->in($period);
        $failures = $journeys->failures();
        $previous = $compare ? $all->in($compare)->failures() : null;

        return [
            'failures' => $failures,
            'delta' => $previous === null ? null : Delta::percent($failures['failures'], $previous['failures']),
            'by_source' => $journeys->failuresBySource(),
            'by_reason' => $journeys->byReason(),
            'series' => $journeys->failureSeries($period, self::grain($this->context)),
            'has_data' => $failures['failures'] > 0,
            'has_attempts' => $failures['attempts'] > 0,
        ];
    }
}
