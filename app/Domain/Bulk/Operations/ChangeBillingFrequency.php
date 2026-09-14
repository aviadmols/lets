<?php

namespace App\Domain\Bulk\Operations;

use App\Domain\Bulk\InvalidBulkEdit;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use Illuminate\Database\Eloquent\Builder;

/**
 * Re-cadence a whole group of recurring subscriptions — "every 1 month" becoming
 * "every 2 months" for four thousand people at once.
 *
 * IT DOES NOT MOVE THE NEXT CHARGE, and that rule is inherited deliberately from
 * the single-subscription action rather than re-decided here. A subscriber due on
 * the 29th stays due on the 29th; the new interval governs the cycle AFTER that,
 * because the orchestrator advances from the date it actually charged on. Moving
 * somebody's next charge because their plan was re-priced is how you charge a
 * person early, and doing it to four thousand people at once is how you do it four
 * thousand times. "Set next charge date" exists for when moving the date IS the
 * intent.
 *
 * MONTHS AND YEARS ONLY, up to twelve of either — the same two units and the same
 * ceiling the detail page offers. Matching it is the point: a bulk edit must never
 * be able to put a subscription into a state its own screen would refuse to set,
 * or the bulk path quietly becomes a way around the rules. (The list's FILTER
 * still offers every cadence the engine can bill, so a legacy weekly book can be
 * found here and moved onto months.)
 *
 * RECURRING ONLY. An instalment plan bills a fixed schedule until it is paid off;
 * it has no cadence to re-negotiate, and the detail page hides the action for one.
 */
final class ChangeBillingFrequency extends ColumnEdit
{
    // === CONSTANTS ===
    public const KEY = 'change_billing_frequency';

    public const PARAM_INTERVAL = 'interval_count';

    public const PARAM_UNIT = 'billing_frequency';

    /**
     * The largest interval a cadence may carry — the same ceiling as
     * ViewSubscription::MAX_INTERVAL. Twelve of anything is a year of months or a
     * dozen years; past that it is a typo, and a typo in a billing interval is a
     * customer who is never charged again.
     */
    public const MAX_INTERVAL = 12;

    /** The two units a subscription business actually re-negotiates. */
    public const UNITS = [BillingFrequency::MONTHLY, BillingFrequency::YEARLY];

    public function key(): string
    {
        return self::KEY;
    }

    public function normalise(array $params): array
    {
        $unit = BillingFrequency::tryFrom((string) ($params[self::PARAM_UNIT] ?? ''));

        if ($unit === null || ! in_array($unit, self::UNITS, true)) {
            throw new InvalidBulkEdit('subscriptions.bulk.error.frequency_unknown');
        }

        $raw = $params[self::PARAM_INTERVAL] ?? null;

        if (! is_numeric($raw)) {
            throw new InvalidBulkEdit('subscriptions.bulk.error.interval_required');
        }

        $interval = (int) $raw;

        if ($interval < 1 || $interval > self::MAX_INTERVAL) {
            throw new InvalidBulkEdit('subscriptions.bulk.error.interval_range');
        }

        return [
            self::PARAM_INTERVAL => $interval,
            self::PARAM_UNIT => $unit->value,
        ];
    }

    /** Live RECURRING plans only — an instalment schedule has no cadence. */
    public function eligible(Builder $query, array $params): Builder
    {
        return parent::eligible($query, $params)->where('plan_kind', PlanKind::RECURRING->value);
    }

    protected function changesFor(InstallmentPlan $plan, array $params): ?array
    {
        $unit = (string) $params[self::PARAM_UNIT];
        $interval = (int) $params[self::PARAM_INTERVAL];

        $isCurrent = $plan->billing_frequency?->value === $unit
            && max(1, (int) $plan->interval_count) === $interval;

        if ($isCurrent) {
            return null; // already on this cadence → skipped, and a re-run is free
        }

        return [
            'billing_frequency' => $unit,
            'interval_count' => $interval,
        ];
    }

    protected function auditFor(InstallmentPlan $plan, array $changes): array
    {
        return [
            'billing_frequency' => [
                'from' => self::cadence($plan->billing_frequency?->value, (int) $plan->interval_count),
                'to' => self::cadence((string) $changes['billing_frequency'], (int) $changes['interval_count']),
            ],
        ];
    }

    /** "2 monthly" — the same shape the single-subscription change writes. */
    private static function cadence(?string $unit, int $interval): string
    {
        return trim(max(1, $interval).' '.($unit ?? ''));
    }

    public function describe(array $params): string
    {
        return __('subscriptions.bulk.op.frequency.summary', [
            'amount' => (int) ($params[self::PARAM_INTERVAL] ?? 1),
            'unit' => __('subscriptions.bulk.unit.'.((string) ($params[self::PARAM_UNIT] ?? '') === BillingFrequency::YEARLY->value ? 'years' : 'months')),
        ]);
    }

    public function preview(InstallmentPlan $plan, array $params): array
    {
        return [
            'before' => self::cadence($plan->billing_frequency?->value, (int) $plan->interval_count),
            'after' => self::cadence((string) $params[self::PARAM_UNIT], (int) $params[self::PARAM_INTERVAL]),
        ];
    }
}
