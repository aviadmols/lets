<?php

namespace App\Domain\Analytics\Products;

use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Subscribers\MovementLog;

/**
 * The MovementLog, attributed to products — pure arithmetic, no database.
 *
 * Each movement row (one subscription crossing the edge of the active book) is
 * split across the product lines of its subscription (a PayPlus plan: one line;
 * a Shopify contract: its lines by unit share). A CANCELLED row whose plan was
 * ended by an account-offer switch is re-typed SWAPPED: the customer did not
 * leave, they moved to another product — so it is not churn.
 */
final class ProductMovements
{
    // === CONSTANTS ===
    public const SWAPPED = 'swapped';

    /** Current-status buckets for "acquired this period, by status now". */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_LAPSED = 'lapsed';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_CANCELLED, self::STATUS_PAUSED, self::STATUS_EXPIRED, self::SWAPPED, self::STATUS_LAPSED];

    /** Plan/contract status → bucket (lower-cased: contracts store upper case). */
    public const STATUS_BUCKETS = [
        'active' => self::STATUS_ACTIVE,
        'paused' => self::STATUS_PAUSED,
        'cancelled' => self::STATUS_CANCELLED,
        'completed' => self::STATUS_EXPIRED,
        'expired' => self::STATUS_EXPIRED,
        'failed' => self::STATUS_LAPSED,
        'awaiting_payment' => self::STATUS_LAPSED,
    ];

    /** @var list<array<string, mixed>> one row per (movement × product line), oldest first */
    private array $lines = [];

    /**
     * @param  list<array<string, mixed>>  $rows  MovementLog::since() rows
     * @param  array<string, array{status: string, swapped: bool, lines: list<array<string, mixed>>}>  $attribution
     */
    public function __construct(array $rows, private readonly array $attribution)
    {
        foreach ($rows as $row) {
            $sub = $attribution[$row['sub']] ?? null;
            $productLines = $sub['lines'] ?? [['pk' => ProductSql::NONE, 'vk' => '', 'title' => null, 'share' => 1.0]];
            $type = $row['type'] === MovementLog::CANCELLED && ($sub['swapped'] ?? false) ? self::SWAPPED : $row['type'];
            foreach ($productLines as $line) {
                $this->lines[] = [
                    'at' => $row['at'],
                    'day' => $row['day'],
                    'sub' => $row['sub'],
                    'type' => $type,
                    'dir' => $row['dir'],
                    'pk' => (string) $line['pk'],
                    'vk' => (string) $line['vk'],
                    'title' => $line['title'],
                    'qty' => $row['qty'] * (float) $line['share'],
                    'mrr' => $row['mrr'] * (float) $line['share'],
                ];
            }
        }
    }

    /** Titles the movement rows carried, per product key (fallback names). @return array<string, ?string> */
    public function titles(): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            $out[$line['pk']] ??= $line['title'];
        }

        return $out;
    }

    /**
     * Units and MRR per product × type inside the window.
     *
     * @return array<string, array<string, array{qty: float, mrr: float, count: int}>> pk => type => totals
     */
    public function totals(Period $window, bool $byVariant = false): array
    {
        $out = [];
        foreach ($this->inWindow($window) as $line) {
            $key = $byVariant ? $line['pk'].'|'.$line['vk'] : $line['pk'];
            $cell = $out[$key][$line['type']] ?? ['qty' => 0.0, 'mrr' => 0.0, 'count' => 0];
            $cell['qty'] += $line['qty'];
            $cell['mrr'] += $line['mrr'];
            $cell['count']++;
            $out[$key][$line['type']] = $cell;
        }

        return $out;
    }

    /**
     * Subscribed units per product at the END of moment $stamp, walked back from
     * today's units: today − additions after it + reductions after it.
     *
     * @param  array<string, float|int>  $now  key => units today
     * @return array<string, float>
     */
    public function unitsAt(array $now, string $stamp, bool $byVariant = false): array
    {
        $out = array_map('floatval', $now);
        foreach ($this->lines as $line) {
            if ($line['at'] > $stamp) {
                $key = $byVariant ? $line['pk'].'|'.$line['vk'] : $line['pk'];
                $out[$key] = ($out[$key] ?? 0.0) - $line['dir'] * $line['qty'];
            }
        }

        return array_map(static fn (float $v): float => max(0.0, $v), $out);
    }

    /**
     * Churned (cancelled, not swapped) MRR or units per product per bucket.
     *
     * @return array{labels: list<string>, products: array<string, list<float>>}
     */
    public function churnSeries(Period $window, Granularity $grain, string $measure): array
    {
        $buckets = $grain->buckets($window);
        $index = $grain->dayIndex($window);
        $products = [];
        foreach ($this->inWindow($window) as $line) {
            if ($line['type'] !== MovementLog::CANCELLED) {
                continue;
            }
            $i = $index[$line['day']] ?? null;
            if ($i === null) {
                continue;
            }
            $products[$line['pk']] ??= array_fill(0, count($buckets), 0.0);
            $products[$line['pk']][$i] += $measure === 'mrr' ? $line['mrr'] : $line['qty'];
        }

        return ['labels' => array_column($buckets, 'label'), 'products' => $products];
    }

    /**
     * Subscriptions that arrived NEW in the window, per product × the status
     * they hold today.
     *
     * @return array<string, array<string, float>> pk => status bucket => units
     */
    public function acquiredByStatus(Period $window): array
    {
        $out = [];
        foreach ($this->inWindow($window) as $line) {
            if ($line['type'] !== MovementLog::NEW) {
                continue;
            }
            $sub = $this->attribution[$line['sub']] ?? null;
            $status = self::STATUS_BUCKETS[strtolower((string) ($sub['status'] ?? ''))] ?? self::STATUS_LAPSED;
            if ($status === self::STATUS_CANCELLED && ($sub['swapped'] ?? false)) {
                $status = self::SWAPPED;
            }
            $out[$line['pk']] ??= array_fill_keys(self::STATUSES, 0.0);
            $out[$line['pk']][$status] += $line['qty'];
        }

        return $out;
    }

    /** @return iterable<array<string, mixed>> */
    private function inWindow(Period $window): iterable
    {
        $from = $window->start()->format('Y-m-d H:i:s');
        $to = $window->end()->format('Y-m-d H:i:s');
        foreach ($this->lines as $line) {
            if ($line['at'] >= $from && $line['at'] <= $to) {
                yield $line;
            }
        }
    }
}
