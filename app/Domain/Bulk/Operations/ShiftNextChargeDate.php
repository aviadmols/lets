<?php

namespace App\Domain\Bulk\Operations;

use App\Domain\Bulk\InvalidBulkEdit;
use App\Models\InstallmentPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Move every matched subscription's next charge by the SAME INTERVAL, keeping the
 * spread between them.
 *
 * The companion to setting one date, and usually the one a merchant actually wants
 * for a large group: "push everybody back a week" while a warehouse is closed
 * leaves four thousand subscribers on four thousand different dates, each a week
 * later. Collapsing them onto one date instead would hand the scheduler four
 * thousand charges in a single morning.
 *
 * Months use NO-OVERFLOW arithmetic — the 31st plus one month is the 28th of
 * February, never the 3rd of March — because that is what BillingFrequency does
 * for an ordinary cycle, and a bulk shift must not put a subscriber on a date their
 * own cadence would never have produced.
 *
 * IT IS NOT IDEMPOTENT, AND THAT IS WHY THE RUNNER'S TRANSACTION MATTERS. Running
 * this twice legitimately shifts twice; a chunk that half-landed and was retried
 * must not. The runner commits rows, audit and cursor together, so a killed chunk
 * unwinds whole and the retry redoes work that never happened.
 *
 * A subscription with NO next charge date is skipped by the eligibility wall
 * rather than given one: there is no "a week later" than nothing, and inventing a
 * date for a plan whose clock is deliberately stopped would schedule a charge
 * nobody asked for.
 */
final class ShiftNextChargeDate extends ColumnEdit
{
    // === CONSTANTS ===
    public const KEY = 'shift_next_charge_date';

    public const PARAM_AMOUNT = 'amount';

    public const PARAM_UNIT = 'unit';

    /** Required when the shift is backwards — see normalise(). */
    public const PARAM_ALLOW_DUE_NOW = 'allow_due_now';

    public const UNIT_DAYS = 'days';

    public const UNIT_WEEKS = 'weeks';

    public const UNIT_MONTHS = 'months';

    /** @var list<string> */
    public const UNITS = [self::UNIT_DAYS, self::UNIT_WEEKS, self::UNIT_MONTHS];

    /**
     * The largest shift in either direction, per unit. A year of days, a year of
     * weeks, two years of months — past that it is a typo, and a typo here is a
     * subscriber who is charged in 2031 or was due in 2019.
     */
    public const MAX_AMOUNT = [
        self::UNIT_DAYS => 365,
        self::UNIT_WEEKS => 52,
        self::UNIT_MONTHS => 24,
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function normalise(array $params): array
    {
        $unit = (string) ($params[self::PARAM_UNIT] ?? '');

        if (! in_array($unit, self::UNITS, true)) {
            throw new InvalidBulkEdit('subscriptions.bulk.error.unit_unknown');
        }

        $raw = $params[self::PARAM_AMOUNT] ?? null;

        if (! is_numeric($raw)) {
            throw new InvalidBulkEdit('subscriptions.bulk.error.amount_required');
        }

        $amount = (int) $raw;

        if ($amount === 0) {
            throw new InvalidBulkEdit('subscriptions.bulk.error.amount_zero');
        }

        $max = self::MAX_AMOUNT[$unit];

        if (abs($amount) > $max) {
            throw new InvalidBulkEdit('subscriptions.bulk.error.amount_too_large');
        }

        // Backwards shifts can pull a charge onto today or earlier, which makes it
        // due on the next scheduler tick. Unlike a fixed date we cannot tell in
        // advance which rows it will happen to, so the acknowledgement is asked for
        // on the DIRECTION rather than on the outcome.
        if ($amount < 0 && ! (bool) ($params[self::PARAM_ALLOW_DUE_NOW] ?? false)) {
            throw new InvalidBulkEdit('subscriptions.bulk.error.shift_backwards');
        }

        return [
            self::PARAM_AMOUNT => $amount,
            self::PARAM_UNIT => $unit,
            self::PARAM_ALLOW_DUE_NOW => $amount < 0,
        ];
    }

    /** A shift needs something to shift: live plans that have a charge date. */
    public function eligible(Builder $query, array $params): Builder
    {
        return parent::eligible($query, $params)->whereNotNull('next_charge_at');
    }

    protected function changesFor(InstallmentPlan $plan, array $params): ?array
    {
        $current = $plan->next_charge_at;

        if ($current === null) {
            return null; // the wall above should have excluded it; belt and braces
        }

        $shifted = self::shift($current, (int) $params[self::PARAM_AMOUNT], (string) $params[self::PARAM_UNIT]);

        return ['next_charge_at' => $shifted->toDateTimeString()];
    }

    protected function auditFor(InstallmentPlan $plan, array $changes): array
    {
        return [
            'next_charge_at' => [
                'from' => $plan->next_charge_at?->toDateString(),
                'to' => Carbon::parse((string) $changes['next_charge_at'])->toDateString(),
            ],
        ];
    }

    /**
     * The arithmetic, in one place so the preview and the write can never disagree.
     *
     * Signed: a negative amount subtracts. Months go through the *NoOverflow
     * variants, which is what stops the 31st of January becoming the 3rd of March.
     */
    public static function shift(Carbon $from, int $amount, string $unit): Carbon
    {
        $base = $from->copy()->startOfDay();
        $n = abs($amount);
        $forward = $amount > 0;

        return match ($unit) {
            self::UNIT_WEEKS => $forward ? $base->addWeeks($n) : $base->subWeeks($n),
            self::UNIT_MONTHS => $forward ? $base->addMonthsNoOverflow($n) : $base->subMonthsNoOverflow($n),
            default => $forward ? $base->addDays($n) : $base->subDays($n),
        };
    }

    public function describe(array $params): string
    {
        $amount = (int) ($params[self::PARAM_AMOUNT] ?? 0);
        $unit = (string) ($params[self::PARAM_UNIT] ?? self::UNIT_DAYS);

        return __($amount >= 0 ? 'subscriptions.bulk.op.shift.summary_forward' : 'subscriptions.bulk.op.shift.summary_back', [
            'amount' => abs($amount),
            'unit' => __('subscriptions.bulk.unit.'.$unit),
        ]);
    }

    public function preview(InstallmentPlan $plan, array $params): array
    {
        $current = $plan->next_charge_at;

        if ($current === null) {
            return ['before' => '—', 'after' => '—'];
        }

        return [
            'before' => $current->toDateString(),
            'after' => self::shift($current, (int) $params[self::PARAM_AMOUNT], (string) $params[self::PARAM_UNIT])->toDateString(),
        ];
    }
}
