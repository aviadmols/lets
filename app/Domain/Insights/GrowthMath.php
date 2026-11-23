<?php

namespace App\Domain\Insights;

use Carbon\CarbonImmutable;

/**
 * The pure arithmetic behind the growth answers — no queries, so every rule is pinned
 * by a unit test with hand-written rows.
 */
final class GrowthMath
{
    // === CONSTANTS ===
    public const PACE_DAYS = 30;

    /**
     * "Passed month N" = paid month N+1 too. Only people whose month N+1 charge is due
     * (plus the retry grace) are asked; the rest have not had the chance to fail yet.
     *
     * @param  list<array{day: string, paid: int}>  $group  first paid day + paid count
     * @return list<array{month: int, eligible: int, passed: int}>
     */
    public static function ladder(array $group, CarbonImmutable $today, int $graceDays, int $months): array
    {
        $out = [];
        for ($n = 1; $n <= $months; $n++) {
            $eligible = array_filter($group, static fn (array $p): bool => CarbonImmutable::parse($p['day'], $today->getTimezone())
                ->addMonthsNoOverflow($n)->addDays($graceDays)->lte($today));
            if ($eligible === []) {
                break;
            }
            $out[] = [
                'month' => $n,
                'eligible' => count($eligible),
                'passed' => count(array_filter($eligible, static fn (array $p): bool => $p['paid'] >= $n + 1)),
            ];
        }

        return $out;
    }

    /**
     * How many subscribers there were on each day from $start to $today, rebuilt BACKWARDS
     * from today's count: each day before a join had one fewer, each day before a leave
     * one more.
     *
     * @param  list<string>  $joinDays
     * @param  list<string>  $leaveDays
     * @return list<array{day: string, count: int, joined: int, left: int}>
     */
    public static function book(int $activeNow, array $joinDays, array $leaveDays, CarbonImmutable $start, CarbonImmutable $today): array
    {
        $joined = array_count_values($joinDays);
        $left = array_count_values($leaveDays);

        $out = [];
        $level = $activeNow;
        for ($d = $today; $d->gte($start); $d = $d->subDay()) {
            $key = $d->format(InsightsClock::DATE);
            $out[] = ['day' => $key, 'count' => $level, 'joined' => $joined[$key] ?? 0, 'left' => $left[$key] ?? 0];
            $level -= ($joined[$key] ?? 0) - ($left[$key] ?? 0);
        }

        return array_reverse($out);
    }

    /**
     * If the last PACE_DAYS' net growth holds: subscribers and revenue now, in one month and
     * in two, and how the net compares with the period before (null = nothing to compare).
     *
     * @param  list<string>  $joinDays
     * @param  list<string>  $leaveDays
     * @return array{joined: int, left: int, net: int, change: ?int, comparable: bool, steps: list<array{months: int, subscribers: int, revenue: float}>}
     */
    public static function pace(array $joinDays, array $leaveDays, int $activeNow, float $activeAmount, CarbonImmutable $today, CarbonImmutable $start): array
    {
        $from = $today->subDays(self::PACE_DAYS - 1);
        $prevFrom = $from->subDays(self::PACE_DAYS);
        $within = static fn (array $days, CarbonImmutable $a, CarbonImmutable $b): int => count(array_filter(
            $days,
            static fn (string $d): bool => $d >= $a->format(InsightsClock::DATE) && $d <= $b->format(InsightsClock::DATE),
        ));

        $joined = $within($joinDays, $from, $today);
        $left = $within($leaveDays, $from, $today);
        $net = $joined - $left;
        $prevNet = $within($joinDays, $prevFrom, $from->subDay()) - $within($leaveDays, $prevFrom, $from->subDay());
        $price = $activeNow > 0 ? $activeAmount / $activeNow : 0.0;

        return [
            'joined' => $joined,
            'left' => $left,
            'net' => $net,
            'change' => $prevNet !== 0 ? (int) round((($net - $prevNet) / abs($prevNet)) * 100) : null,
            // Fair only when the system saw the whole earlier period.
            'comparable' => $prevFrom->gte($start),
            'steps' => array_map(static fn (int $m): array => [
                'months' => $m,
                'subscribers' => max(0, $activeNow + $net * $m),
                'revenue' => round(max(0.0, $activeAmount + $net * $m * $price), 2),
            ], [0, 1, 2]),
        ];
    }
}
