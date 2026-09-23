<?php

namespace App\Domain\Bulk\Operations;

use App\Domain\Bulk\InvalidBulkEdit;
use App\Models\InstallmentPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Move every matched subscription's NEXT CHARGE to one given date.
 *
 * This is the operation the whole screen was asked for: "everyone who bills on the
 * 3rd now bills on the 10th" is a date filter and this verb. It writes
 * `next_charge_at` and NOTHING else — not the cadence, not the amount, not the
 * next order's contents. The cycles after this one keep falling where the plan's
 * own frequency puts them, because the orchestrator advances from the date it
 * actually charged on.
 *
 * BOTH PLAN KINDS. The single-subscription "Edit next charge" is recurring-only
 * because it also edits the next order's line items, which an instalment plan does
 * not have. A pure date move has no such problem: `next_charge_at` is the one clock
 * the scheduler reads for both kinds (DispatchDuePlansCommand), so a deposit
 * customer's remaining instalment can be rescheduled here exactly like a
 * subscriber's next box.
 *
 * A DATE THAT IS ALREADY DUE IS A MONEY EVENT. Today, or any day before it, makes
 * every matched subscription due on the next scheduler tick — forty thousand cards
 * charged within the hour. That is a legitimate thing to want (catching up a
 * migration that was parked) and a catastrophic thing to do by accident, so it is
 * refused unless the merchant has ticked the acknowledgement that says so out loud.
 */
final class SetNextChargeDate extends ColumnEdit
{
    // === CONSTANTS ===
    public const KEY = 'set_next_charge_date';

    /** The merchant's parameter: the date every matched subscription moves to. */
    public const PARAM_DATE = 'date';

    /**
     * The acknowledgement that a due-now date means "charge these now". Required
     * only when the date actually is due now; invisible the rest of the time.
     */
    public const PARAM_ALLOW_DUE_NOW = 'allow_due_now';

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * A subscriber the shop gives away is not given a charge date.
     *
     * This is the one verb that can hand a clock to a plan that has none, and a
     * comped plan has none by design. The engine would refuse the charge anyway,
     * but the date is not harmless on its own: it puts the person on the
     * upcoming-charges screen as money that is coming in, and it is what the
     * reminder email reads to tell them a sum will be taken on a day.
     *
     * Mirrors the detail page, which no longer offers "Edit next charge" for one
     * — the bulk screen may never do what the single screen refuses.
     */
    public function eligible(Builder $query, array $params): Builder
    {
        return parent::eligible($query, $params)->where('no_charge', false);
    }

    public function normalise(array $params): array
    {
        $raw = $params[self::PARAM_DATE] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            throw new InvalidBulkEdit('subscriptions.bulk.error.date_required');
        }

        try {
            $date = Carbon::parse(trim($raw))->startOfDay();
        } catch (\Throwable) {
            throw new InvalidBulkEdit('subscriptions.bulk.error.date_unreadable');
        }

        $dueNow = $date->lessThanOrEqualTo(now()->startOfDay());
        $acknowledged = (bool) ($params[self::PARAM_ALLOW_DUE_NOW] ?? false);

        if ($dueNow && ! $acknowledged) {
            throw new InvalidBulkEdit('subscriptions.bulk.error.date_due_now');
        }

        return [
            self::PARAM_DATE => $date->toDateString(),
            self::PARAM_ALLOW_DUE_NOW => $dueNow,
        ];
    }

    protected function changesFor(InstallmentPlan $plan, array $params): ?array
    {
        $target = (string) $params[self::PARAM_DATE];

        // Already there → skipped, not written. This is also the re-run guard: a
        // chunk replayed after a rolled-back attempt finds nothing left to do.
        if ($plan->next_charge_at?->toDateString() === $target) {
            return null;
        }

        // Start of day, like the single-subscription edit: a charge date is a day,
        // and carrying a time of day into it makes "the 10th" mean 14:37 on the
        // 10th for one subscriber and 08:02 for the next.
        //
        // A STRING, not a Carbon: every row in this chunk is getting the identical
        // value, and identical strings are what let ColumnEdit collapse them into
        // one statement. Two Carbon instances of the same instant are not the same
        // group key.
        return ['next_charge_at' => Carbon::parse($target)->startOfDay()->toDateTimeString()];
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

    public function describe(array $params): string
    {
        return __('subscriptions.bulk.op.set_date.summary', [
            'date' => (string) ($params[self::PARAM_DATE] ?? '—'),
        ]);
    }

    public function preview(InstallmentPlan $plan, array $params): array
    {
        return [
            'before' => $plan->next_charge_at?->toDateString() ?? '—',
            'after' => (string) ($params[self::PARAM_DATE] ?? '—'),
        ];
    }
}
