<?php

namespace App\Domain\Analytics\Payments;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Delta;
use Carbon\CarbonImmutable;

/**
 * Payments › Overview — the numbers (spec §3.1). Never formats or translates.
 *
 *   Headline KPIs + the 12-month chart  ← LedgerTotals (final status per charge, both rails)
 *   Recovered, first attempt, cycles,
 *   payments over time                  ← PaymentJourneys (attempt history on the Timeline)
 *
 * Not here, honestly: backup-card metrics (LETS charges one saved token per
 * plan — no backup attempt exists) and the country split (no customer
 * country is stored). The screen renders those as not tracked.
 */
final class PaymentsOverviewQuery
{
    // === CONSTANTS ===
    public const CACHE_LEDGER = 'payments.overview.ledger';

    public const CACHE_MONTHLY = 'payments.overview.monthly';

    public const CHART_OVER_TIME = 'payments_over_time';

    public const UNIT_COUNT = 'count';

    public const UNIT_REVENUE = 'revenue';

    public const UNITS = [self::UNIT_COUNT, self::UNIT_REVENUE];

    /** The success-vs-failed chart always reads a year, whatever the period (spec §3.1.2). */
    public const MONTHS = 12;

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
        $money = $this->context->option('unit', self::UNITS) === self::UNIT_REVENUE;
        $field = $money ? 'amount' : 'count';

        $ledger = $this->ledger();
        $now = LedgerTotals::fold($ledger['current']);
        $prev = $ledger['previous'] !== null ? LedgerTotals::fold($ledger['previous']) : null;

        $all = new JourneyStats(PaymentJourneys::for($period, $this->context->filters));
        $journeys = $all->in($period);
        $previousJourneys = $compare ? $all->in($compare) : null;

        $recovered = $journeys->recovery();
        $recoveredPrev = $previousJourneys?->recovery();

        $kpi = static fn (float|int|null $value, float|int|null $previous, bool $rate = false): array => [
            'value' => $value,
            'previous' => $previous,
            'delta' => $rate ? Delta::points($value, $previous) : Delta::percent($value, $previous),
        ];
        $successKey = $money ? 'success_amount' : 'success';

        $sources = [];
        foreach (PaymentJourneys::SOURCES as $source) {
            if ($source === PaymentJourneys::SOURCE_SHOPIFY && ! $this->context->filters->includesContracts()) {
                continue;
            }
            $s = LedgerTotals::fold($ledger['current'], $source);
            if ($s['attempted']['count'] === 0) {
                continue;
            }
            $j = $journeys->source($source);
            $sources[] = [
                'source' => $source,
                'attempted' => $s['attempted'][$field],
                'realized' => $s['realized']['amount'],
                'under' => $s['under']['count'],
                'realization' => $s[$successKey],
                'first_attempt' => $j->firstAttempt()['rate'],
                'first_cycle' => $source === PaymentJourneys::SOURCE_PAYPLUS ? $j->cycle(JourneyStats::CYCLE_FIRST)['rate'] : null,
                'subsequent_cycle' => $source === PaymentJourneys::SOURCE_PAYPLUS ? $j->cycle(JourneyStats::CYCLE_SUBSEQUENT)['rate'] : null,
            ];
        }

        return [
            'money' => $money,
            'kpis' => [
                'attempted' => $kpi($now['attempted'][$field], $prev['attempted'][$field] ?? null),
                'success' => $kpi($now[$successKey], $prev[$successKey] ?? null, true),
                'recovered' => $kpi($money ? $recovered['realized'] : $recovered['recovered'], $recoveredPrev === null ? null : ($money ? $recoveredPrev['realized'] : $recoveredPrev['recovered'])),
                'under' => $kpi($now['under'][$field], $prev['under'][$field] ?? null),
                'lost' => $kpi($now['lost'][$field], $prev['lost'][$field] ?? null),
            ],
            'monthly' => $this->monthly($field, $successKey),
            'first_attempt' => $journeys->firstAttempt(),
            'first_cycle' => $journeys->cycle(JourneyStats::CYCLE_FIRST),
            'subsequent_cycle' => $journeys->cycle(JourneyStats::CYCLE_SUBSEQUENT),
            'sources' => $sources,
            'over_time' => $journeys->outcomeSeries($period, self::grain($this->context), $money),
            'has_journeys' => ! $journeys->isEmpty(),
            'has_data' => $now['attempted']['count'] > 0 || ! $journeys->isEmpty(),
        ];
    }

    /** @return array{current: array<string, mixed>, previous: ?array<string, mixed>} */
    private function ledger(): array
    {
        $period = $this->context->period;
        $filters = $this->context->filters;

        return AnalyticsCache::remember(self::CACHE_LEDGER, $period, $filters, function () use ($period, $filters): array {
            $totals = new LedgerTotals($filters);
            $compare = $period->comparison();

            return [
                'current' => $totals->grouped($period->start(), $period->end())['all'] ?? [],
                'previous' => $compare ? ($totals->grouped($compare->start(), $compare->end())['all'] ?? []) : null,
            ];
        });
    }

    /**
     * The last twelve calendar months, oldest first: realized / under recovery / lost + success %.
     *
     * @return array{months: list<string>, realized: list<float>, under: list<float>, lost: list<float>, success: list<?float>}
     */
    private function monthly(string $field, string $successKey): array
    {
        $period = $this->context->period;
        $filters = $this->context->filters;
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(self::MONTHS - 1);

        $grouped = AnalyticsCache::remember(self::CACHE_MONTHLY, $period, $filters, fn (): array => (new LedgerTotals($filters))
            ->grouped($start, CarbonImmutable::now()->endOfDay(), 'month'), $start->format('Y-m'));

        $out = ['months' => [], 'realized' => [], 'under' => [], 'lost' => [], 'success' => []];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = $start->addMonths($i);
            $s = LedgerTotals::fold($grouped[$month->format('Y-m')] ?? []);
            $out['months'][] = $month->format('Y-m');
            $out['realized'][] = (float) $s['realized'][$field];
            $out['under'][] = (float) $s['under'][$field];
            $out['lost'][] = (float) $s['lost'][$field];
            $out['success'][] = $s[$successKey];
        }

        return $out;
    }
}
