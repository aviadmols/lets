<?php

namespace App\Domain\Insights;

use App\Domain\Analytics\Cancellations\CancellationLog;
use App\Domain\Analytics\Filters;
use Carbon\CarbonImmutable;

/**
 * Who left, when, why, and what they were paying — for the churn screen.
 *
 * A thin reading of the engine's own CancellationLog (the activity_events state-machine
 * trail), so "left" means exactly what every other count in the app means by it. Two
 * kinds, kept apart because the merchant answers them differently:
 *   - CANCELLED  — the subscriber chose to leave (has a reason, maybe);
 *   - STOPPED PAYING — a charge failed and never came back (involuntary churn).
 */
final class Departures
{
    // === CONSTANTS ===
    public const KIND_CANCELLED = 'cancelled';

    public const KIND_STOPPED_PAYING = 'stopped_paying';

    /** A reason the engine writes for itself — shown in words, never as the raw code. */
    public const SYSTEM_REASON_PREFIXES = ['account_offer' => 'switched', 'order_refunded' => 'refunded'];

    /**
     * @return list<array{day: string, plan: int, name: string, product: string, kind: string, reason: ?string, mrr: float}>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = (new CancellationLog(Filters::none()))->between($from->startOfDay(), $to->endOfDay());

        return array_values(array_map(static fn (array $r): array => [
            'day' => (string) $r['day'],
            'plan' => (int) $r['sub'],
            'rail' => (string) $r['rail'],
            'name' => (string) $r['name'],
            'product' => (string) $r['product_title'],
            'kind' => $r['to'] === 'cancelled' ? self::KIND_CANCELLED : self::KIND_STOPPED_PAYING,
            'reason' => self::reasonKey((string) $r['reason']),
            'reason_text' => trim((string) $r['reason']),
            'mrr' => round((float) $r['mrr'], 2),
        ], $rows));
    }

    /**
     * A reason as the screen keys it: null = none given, "sys:<key>" = one the engine wrote
     * (translated), anything else = the merchant's own words, shown as written.
     */
    public static function reasonKey(string $reason): ?string
    {
        $reason = trim($reason);
        if ($reason === '') {
            return null;
        }

        foreach (self::SYSTEM_REASON_PREFIXES as $prefix => $key) {
            if (str_starts_with($reason, $prefix)) {
                return 'sys:'.$key;
            }
        }

        return $reason;
    }
}
