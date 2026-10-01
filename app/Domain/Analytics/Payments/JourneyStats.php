<?php

namespace App\Domain\Analytics\Payments;

use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Support\Delta;

/**
 * Numbers over a list of payment journeys (PaymentJourneys) — pure PHP, no
 * database, so every rule below is pinned by a unit-sized test.
 *
 * "Cycles": LETS retries the SAME cycle on an interval up to the shop's max
 * attempts; there are no Loop-style recovery cycles. As the data map decides,
 * each retry is one cycle:
 *   first recovery cycle   = retry #1 (the 2nd attempt) of a payment whose first attempt failed;
 *   subsequent cycles      = retry #2 onwards.
 * A payment enters a cycle when the previous attempt failed; inside it, it is
 * recovered (paid at that retry), under recovery (still waiting for it), lost
 * (the ladder ended there) — or it moves on to the next cycle.
 *
 * Rates are shares of the population that entered the stage; a still-pending
 * Shopify attempt is not settled and stays out of every rate.
 */
final class JourneyStats
{
    // === CONSTANTS ===
    public const CYCLE_FIRST = 'first';

    public const CYCLE_SUBSEQUENT = 'subsequent';

    /** @param list<array<string, mixed>> $journeys */
    public function __construct(private readonly array $journeys) {}

    /** Journeys whose first attempt is inside $period. */
    public function in(Period $period): self
    {
        $start = $period->start()->format('Y-m-d');
        $end = $period->end()->format('Y-m-d');

        return new self(array_values(array_filter(
            $this->journeys,
            static fn (array $j): bool => $j['day'] >= $start && $j['day'] <= $end,
        )));
    }

    public function source(string $source): self
    {
        return new self(array_values(array_filter($this->journeys, static fn (array $j): bool => $j['source'] === $source)));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->journeys;
    }

    public function isEmpty(): bool
    {
        return $this->journeys === [];
    }

    /** @return array{attempted: int, succeeded: int, realized: float, rate: ?float} */
    public function firstAttempt(): array
    {
        $settled = array_filter($this->journeys, static fn (array $j): bool => $j['outcome'] !== PaymentJourneys::PENDING);
        $ok = array_filter($this->journeys, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::SUCCEEDED);

        return [
            'attempted' => count($this->journeys),
            'succeeded' => count($ok),
            'realized' => round(array_sum(array_column($ok, 'amount')), 2),
            'rate' => $settled === [] ? null : Delta::share(count($ok), count($settled)),
        ];
    }

    /**
     * One recovery stage.
     *
     * @return array{attempted: int, recovered: int, realized: float, under: int, lost: int, lost_failed: int, lost_stopped: int, rate: ?float}
     */
    public function cycle(string $cycle): array
    {
        $first = $cycle === self::CYCLE_FIRST;
        $in = array_filter($this->journeys, static fn (array $j): bool => self::entered($j, $first ? 1 : 2));

        $recovered = array_filter($in, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::RECOVERED
            && ($first ? $j['recovered_attempt'] === 2 : $j['recovered_attempt'] >= 3));
        $under = array_filter($in, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::UNDER_RECOVERY
            && ($first ? $j['attempts'] === 1 : $j['attempts'] >= 2));
        $lost = array_filter($in, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::LOST
            && ($first ? $j['attempts'] <= 2 : $j['attempts'] >= 3));

        return [
            'attempted' => count($in),
            'recovered' => count($recovered),
            'realized' => round(array_sum(array_column($recovered, 'amount')), 2),
            'under' => count($under),
            'lost' => count($lost),
            'lost_failed' => count(array_filter($lost, static fn (array $j): bool => $j['lost_reason'] === PaymentJourneys::LOST_PAYMENT_FAILED)),
            'lost_stopped' => count(array_filter($lost, static fn (array $j): bool => $j['lost_reason'] === PaymentJourneys::LOST_STOPPED)),
            'rate' => $in === [] ? null : Delta::share(count($recovered), count($in)),
        ];
    }

    /**
     * Did this journey reach recovery stage $n (1 = retry #1, 2 = retry #2+)?
     * Reaching it needs $n failed attempts and either another attempt or still
     * waiting for one; a PayPlus payment only (Shopify has no retry history).
     */
    public static function entered(array $j, int $n): bool
    {
        if ($j['source'] !== PaymentJourneys::SOURCE_PAYPLUS || $j['failures'] < $n) {
            return false;
        }
        if ($n === 1) {
            return true;
        }

        return $j['attempts'] > $n || $j['outcome'] === PaymentJourneys::UNDER_RECOVERY;
    }

    /** @return array{failed: int, recovered: int, under: int, lost: int, retry: int, card_update: int, rate: ?float, realized: float} */
    public function recovery(): array
    {
        $failed = array_filter($this->journeys, static fn (array $j): bool => $j['failures'] > 0 && $j['source'] === PaymentJourneys::SOURCE_PAYPLUS);
        $recovered = array_filter($failed, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::RECOVERED);

        return [
            'failed' => count($failed),
            'recovered' => count($recovered),
            'under' => count(array_filter($failed, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::UNDER_RECOVERY)),
            'lost' => count(array_filter($failed, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::LOST)),
            'retry' => count(array_filter($recovered, static fn (array $j): bool => $j['via'] === PaymentJourneys::VIA_RETRY)),
            'card_update' => count(array_filter($recovered, static fn (array $j): bool => $j['via'] === PaymentJourneys::VIA_CARD_UPDATE)),
            'rate' => $failed === [] ? null : Delta::share(count($recovered), count($failed)),
            'realized' => round(array_sum(array_column($recovered, 'amount')), 2),
        ];
    }

    /**
     * Every failed first attempt (both rails), with the shares the Failures
     * screen prints.
     *
     * @return array{failures: int, attempts: int, rate: ?float, recovered: int, under: int, lost: int, entered: int}
     */
    public function failures(): array
    {
        $failed = array_filter($this->journeys, static fn (array $j): bool => $j['failures'] > 0);

        return [
            'failures' => count($failed),
            'attempts' => count($this->journeys),
            'rate' => $this->journeys === [] ? null : Delta::share(count($failed), count($this->journeys)),
            'recovered' => count(array_filter($failed, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::RECOVERED)),
            'under' => count(array_filter($failed, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::UNDER_RECOVERY)),
            'lost' => count(array_filter($failed, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::LOST)),
            'entered' => count(array_filter($failed, static fn (array $j): bool => self::entered($j, 1))),
        ];
    }

    /** @return array<string, int> reason bucket => failed first attempts, biggest first */
    public function byReason(): array
    {
        $out = [];
        foreach ($this->journeys as $j) {
            if ($j['failures'] > 0) {
                $reason = $j['reason'] ?? DeclineReason::OTHER;
                $out[$reason] = ($out[$reason] ?? 0) + 1;
            }
        }
        arsort($out);

        return $out;
    }

    /** @return array<string, int> source => failed first attempts */
    public function failuresBySource(): array
    {
        $out = [];
        foreach ($this->journeys as $j) {
            if ($j['failures'] > 0) {
                $out[$j['source']] = ($out[$j['source']] ?? 0) + 1;
            }
        }
        arsort($out);

        return $out;
    }

    /**
     * Per reason: attempts (failed payments), recovered, under, rate, via retry / card update.
     *
     * @return list<array<string, mixed>>
     */
    public function recoveryByReason(): array
    {
        $groups = [];
        foreach ($this->journeys as $j) {
            if ($j['failures'] > 0 && $j['source'] === PaymentJourneys::SOURCE_PAYPLUS) {
                $groups[$j['reason'] ?? DeclineReason::OTHER][] = $j;
            }
        }

        $out = [];
        foreach ($groups as $reason => $rows) {
            $stats = (new self($rows))->recovery();
            $out[] = ['reason' => (string) $reason] + $stats;
        }
        usort($out, static fn (array $a, array $b): int => $b['failed'] <=> $a['failed']);

        return $out;
    }

    /**
     * Per retry number: payments that were retried that many times, and how
     * many came back AT that retry (split by path).
     *
     * @return list<array{retry: int, attempts: int, recovered: int, rate: ?float, retry_path: int, card_update: int}>
     */
    public function byRetry(): array
    {
        $max = 0;
        foreach ($this->journeys as $j) {
            if ($j['source'] === PaymentJourneys::SOURCE_PAYPLUS && $j['failures'] > 0) {
                $max = max($max, $j['attempts'] - 1, $j['outcome'] === PaymentJourneys::UNDER_RECOVERY ? $j['attempts'] : 0);
            }
        }

        $out = [];
        for ($n = 1; $n <= $max; $n++) {
            // Retry #n is attempt n+1; a payment reached it when n attempts failed before it.
            $reached = array_filter($this->journeys, static fn (array $j): bool => $j['source'] === PaymentJourneys::SOURCE_PAYPLUS
                && $j['failures'] >= $n && ($j['attempts'] > $n || $j['outcome'] === PaymentJourneys::UNDER_RECOVERY));
            $won = array_filter($reached, static fn (array $j): bool => $j['outcome'] === PaymentJourneys::RECOVERED && $j['recovered_attempt'] === $n + 1);
            $out[] = [
                'retry' => $n,
                'attempts' => count($reached),
                'recovered' => count($won),
                'rate' => $reached === [] ? null : Delta::share(count($won), count($reached)),
                'retry_path' => count(array_filter($won, static fn (array $j): bool => $j['via'] === PaymentJourneys::VIA_RETRY)),
                'card_update' => count(array_filter($won, static fn (array $j): bool => $j['via'] === PaymentJourneys::VIA_CARD_UPDATE)),
            ];
        }

        return $out;
    }

    /**
     * Recoveries per bucket by the day they were RECOVERED, split by path.
     *
     * @return array{labels: list<string>, retry: list<float>, card_update: list<float>}
     */
    public function recoverySeries(Period $period, Granularity $grain): array
    {
        $retry = [];
        $card = [];
        foreach ($this->journeys as $j) {
            if ($j['outcome'] !== PaymentJourneys::RECOVERED) {
                continue;
            }
            if ($j['via'] === PaymentJourneys::VIA_CARD_UPDATE) {
                $card[$j['recovered_day']] = ($card[$j['recovered_day']] ?? 0) + 1;
            } else {
                $retry[$j['recovered_day']] = ($retry[$j['recovered_day']] ?? 0) + 1;
            }
        }

        return [
            'labels' => array_column($grain->buckets($period), 'label'),
            'retry' => $grain->rollUp($period, $retry),
            'card_update' => $grain->rollUp($period, $card),
        ];
    }

    /**
     * Failed first attempts per bucket, one series per reason.
     *
     * @return array{labels: list<string>, reasons: array<string, list<float>>}
     */
    public function failureSeries(Period $period, Granularity $grain): array
    {
        $byReason = [];
        foreach ($this->journeys as $j) {
            if ($j['failures'] > 0) {
                $reason = $j['reason'] ?? DeclineReason::OTHER;
                $byReason[$reason][$j['day']] = ($byReason[$reason][$j['day']] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($byReason as $reason => $days) {
            $out[$reason] = $grain->rollUp($period, $days);
        }

        return ['labels' => array_column($grain->buckets($period), 'label'), 'reasons' => $out];
    }

    /**
     * Payments per bucket of their first attempt, by how they ended.
     *
     * @return array{labels: list<string>, first: list<float>, recovered: list<float>, under: list<float>, lost: list<float>}
     */
    public function outcomeSeries(Period $period, Granularity $grain, bool $money): array
    {
        $by = [
            PaymentJourneys::SUCCEEDED => [],
            PaymentJourneys::RECOVERED => [],
            PaymentJourneys::UNDER_RECOVERY => [],
            PaymentJourneys::LOST => [],
        ];
        foreach ($this->journeys as $j) {
            if (! isset($by[$j['outcome']])) {
                continue;
            }
            $by[$j['outcome']][$j['day']] = ($by[$j['outcome']][$j['day']] ?? 0) + ($money ? $j['amount'] : 1);
        }

        return [
            'labels' => array_column($grain->buckets($period), 'label'),
            'first' => $grain->rollUp($period, $by[PaymentJourneys::SUCCEEDED]),
            'recovered' => $grain->rollUp($period, $by[PaymentJourneys::RECOVERED]),
            'under' => $grain->rollUp($period, $by[PaymentJourneys::UNDER_RECOVERY]),
            'lost' => $grain->rollUp($period, $by[PaymentJourneys::LOST]),
        ];
    }
}
