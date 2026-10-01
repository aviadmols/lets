<?php

namespace App\Domain\Analytics\Subscribers;

use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Period;
use Carbon\CarbonImmutable;

/**
 * Pure arithmetic over a MovementLog + today's ActiveBook — no database.
 *
 * TWO LEVELS:
 *   subscription — every movement row as it is;
 *   subscriber   — a person crosses the edge of the book only when their FIRST
 *                  active subscription arrives (0 → 1) or their LAST one leaves
 *                  (1 → 0). Found by walking each person's movements BACKWARDS
 *                  from the number of active subscriptions they hold today.
 *
 * HISTORY IS WALKED BACK FROM TODAY: the active count at any past moment is
 * today's count minus every addition after it plus every reduction after it,
 * so every line ends exactly on the number the KPI card shows.
 */
final class MovementSummary
{
    // === CONSTANTS ===
    public const LEVEL_SUBSCRIPTION = 'subscription';

    public const LEVEL_SUBSCRIBER = 'subscriber';

    /** @var list<array<string, mixed>> person-level crossings, oldest first */
    private array $crossings;

    /**
     * @param  list<array{at: string, day: string, sub: string, key: string, type: string, dir: int, qty: int, mrr: float, born: string, actor: string}>  $rows  oldest first
     * @param  array{subscribers: int, subscriptions: int, quantity: int, mrr: float}  $now
     * @param  array<string, int>  $perCustomerNow  active subscriptions per customer key today
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $now,
        array $perCustomerNow,
    ) {
        $this->crossings = self::crossings($rows, $perCustomerNow);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $perCustomerNow
     * @return list<array<string, mixed>>
     */
    public static function crossings(array $rows, array $perCustomerNow): array
    {
        $byCustomer = [];
        foreach ($rows as $i => $row) {
            $byCustomer[$row['key']][] = $i;
        }

        $out = [];
        foreach ($byCustomer as $key => $indexes) {
            $after = max(0, (int) ($perCustomerNow[$key] ?? 0));
            for ($j = count($indexes) - 1; $j >= 0; $j--) {
                $row = $rows[$indexes[$j]];
                $before = max(0, $after - $row['dir']);
                if (($row['dir'] > 0 && $before === 0) || ($row['dir'] < 0 && $after === 0)) {
                    $out[$indexes[$j]] = $row;
                }
                $after = $before;
            }
        }
        ksort($out);

        return array_values($out);
    }

    /** @return list<array<string, mixed>> */
    private function level(string $level): array
    {
        return $level === self::LEVEL_SUBSCRIBER ? $this->crossings : $this->rows;
    }

    // === Point-in-time values ===

    /** Active count of $level at the END of moment $t. */
    public function activeAt(string $level, CarbonImmutable $t): int
    {
        $value = $level === self::LEVEL_SUBSCRIBER ? $this->now['subscribers'] : $this->now['subscriptions'];
        $stamp = $t->format('Y-m-d H:i:s');
        foreach ($this->level($level) as $row) {
            if ($row['at'] > $stamp) {
                $value -= $row['dir'];
            }
        }

        return max(0, $value);
    }

    /** Subscribed units at moment $t (subscription level). */
    public function quantityAt(CarbonImmutable $t): int
    {
        $value = $this->now['quantity'];
        $stamp = $t->format('Y-m-d H:i:s');
        foreach ($this->rows as $row) {
            if ($row['at'] > $stamp) {
                $value -= $row['dir'] * $row['qty'];
            }
        }

        return max(0, $value);
    }

    /** Active MRR at moment $t (each movement carries its subscription's MRR). */
    public function mrrAt(CarbonImmutable $t): float
    {
        $value = $this->now['mrr'];
        $stamp = $t->format('Y-m-d H:i:s');
        foreach ($this->rows as $row) {
            if ($row['at'] > $stamp) {
                $value -= $row['dir'] * $row['mrr'];
            }
        }

        return round(max(0.0, $value), 2);
    }

    // === Window totals ===

    /** @return array<string, int> movement type => count inside the window */
    public function counts(string $level, Period $window): array
    {
        $out = array_fill_keys(MovementLog::TYPES, 0);
        foreach ($this->inWindow($this->level($level), $window) as $row) {
            $out[$row['type']]++;
        }

        return $out;
    }

    /** @return array<string, int> movement type => units inside the window */
    public function quantities(Period $window): array
    {
        $out = array_fill_keys(MovementLog::TYPES, 0);
        foreach ($this->inWindow($this->rows, $window) as $row) {
            $out[$row['type']] += $row['qty'];
        }

        return $out;
    }

    /**
     * Churn for a window, subscriber level.
     *
     * @return array{lost: int, start_active: int, rate: ?float, zero_day: int, zero_day_share: ?float, cancelled_mrr: float}
     */
    public function churn(Period $window): array
    {
        $lost = 0;
        $zeroDay = 0;
        foreach ($this->inWindow($this->crossings, $window) as $row) {
            if ($row['type'] === MovementLog::CANCELLED) {
                $lost++;
                if ($row['day'] === $row['born']) {
                    $zeroDay++;
                }
            }
        }

        $cancelledMrr = 0.0;
        foreach ($this->inWindow($this->rows, $window) as $row) {
            if ($row['type'] === MovementLog::CANCELLED) {
                $cancelledMrr += $row['mrr'];
            }
        }

        $startActive = $this->activeAt(self::LEVEL_SUBSCRIBER, $window->start()->subSecond());

        return [
            'lost' => $lost,
            'start_active' => $startActive,
            'rate' => $startActive > 0 ? round($lost / $startActive * 100, 1) : null,
            'zero_day' => $zeroDay,
            'zero_day_share' => $lost > 0 ? round($zeroDay / $lost * 100, 1) : null,
            'cancelled_mrr' => round($cancelledMrr, 2),
        ];
    }

    // === Series ===

    /**
     * Per bucket: a count per movement type and the active line at the bucket's end.
     *
     * @return array{labels: list<string>, types: array<string, list<int>>, active: list<int>}
     */
    public function series(string $level, Period $window, Granularity $grain): array
    {
        $buckets = $grain->buckets($window);
        $index = $grain->dayIndex($window);
        $types = array_fill_keys(MovementLog::TYPES, array_fill(0, count($buckets), 0));

        foreach ($this->inWindow($this->level($level), $window) as $row) {
            $i = $index[$row['day']] ?? null;
            if ($i !== null) {
                $types[$row['type']][$i]++;
            }
        }

        return [
            'labels' => array_column($buckets, 'label'),
            'types' => $types,
            'active' => array_map(fn (array $b): int => $this->activeAt($level, $b['end']), $buckets),
        ];
    }

    /** @return array{labels: list<string>, values: list<float>} cancelled MRR per bucket */
    public function cancelledMrrSeries(Period $window, Granularity $grain): array
    {
        $buckets = $grain->buckets($window);
        $index = $grain->dayIndex($window);
        $values = array_fill(0, count($buckets), 0.0);

        foreach ($this->inWindow($this->rows, $window) as $row) {
            $i = $index[$row['day']] ?? null;
            if ($i !== null && $row['type'] === MovementLog::CANCELLED) {
                $values[$i] += $row['mrr'];
            }
        }

        return ['labels' => array_column($buckets, 'label'), 'values' => array_map(static fn (float $v): float => round($v, 2), $values)];
    }

    /** @return iterable<array<string, mixed>> */
    private function inWindow(array $rows, Period $window): iterable
    {
        $from = $window->start()->format('Y-m-d H:i:s');
        $to = $window->end()->format('Y-m-d H:i:s');
        foreach ($rows as $row) {
            if (MovementLog::countsIn($row, $from, $to)) { // the dunning churn rule
                yield $row;
            }
        }
    }
}
