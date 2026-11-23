<?php

namespace App\Domain\Insights;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Carbon\CarbonImmutable;

/**
 * What is expected to be charged on each coming day, and how many subscribers.
 *
 * Every live subscription's next charge, then every cycle after it inside the window,
 * stepped by its own frequency. A charge already overdue counts as due today. Free
 * subscriptions (no_charge) are never a charge.
 */
final class ChargeForecast
{
    // === CONSTANTS ===
    public const STATUSES = [PlanStatus::ACTIVE, PlanStatus::AWAITING_PAYMENT];

    /** Safety stop per plan — a daily plan over half a year is 183 steps. */
    private const MAX_STEPS = 400;

    /** @return array<string, array{count: int, amount: float}> day => totals, only days with charges */
    public function days(int $days): array
    {
        $today = InsightsClock::today();
        $end = $today->addDays(max(1, $days) - 1);

        $plans = InstallmentPlan::query()
            ->where('plan_kind', PlanKind::RECURRING->value)
            ->whereIn('status', array_map(static fn (PlanStatus $s): string => $s->value, self::STATUSES))
            ->where('no_charge', false)
            ->whereNotNull('next_charge_at')
            ->get(['id', 'shop_id', 'billing_frequency', 'next_charge_at', 'installment_amount']);

        $out = [];
        foreach ($plans as $plan) {
            $at = CarbonImmutable::parse($plan->next_charge_at, 'UTC')->setTimezone(InsightsClock::TIMEZONE)->startOfDay();
            if ($at->lt($today)) {
                $at = $today;
            }
            for ($i = 0; $i < self::MAX_STEPS && $at->lte($end); $i++) {
                $key = $at->format(InsightsClock::DATE);
                $out[$key] ??= ['count' => 0, 'amount' => 0.0];
                $out[$key]['count']++;
                $out[$key]['amount'] += (float) $plan->installment_amount;
                $at = self::next($at, $plan->billing_frequency);
            }
        }
        ksort($out);

        return array_map(static fn (array $d): array => ['count' => $d['count'], 'amount' => round($d['amount'], 2)], $out);
    }

    private static function next(CarbonImmutable $at, ?BillingFrequency $frequency): CarbonImmutable
    {
        return match ($frequency) {
            BillingFrequency::DAILY => $at->addDay(),
            BillingFrequency::WEEKLY => $at->addWeek(),
            BillingFrequency::BIWEEKLY => $at->addWeeks(2),
            BillingFrequency::QUARTERLY => $at->addMonthsNoOverflow(3),
            BillingFrequency::YEARLY => $at->addYearNoOverflow(),
            default => $at->addMonthNoOverflow(),
        };
    }
}
