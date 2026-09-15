<?php

namespace App\Domain\Lifecycle;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOutcome;

/**
 * Out-of-schedule "Charge now" (admin trigger). Deliberately a THIN wrapper over the
 * ChargeOrchestrator so it inherits every money-safety law unchanged: the row lock,
 * the idempotent short-circuit, the one-charge-a-day wall (RepeatChargeGuard — a
 * second press no longer charges the NEXT cycle, which is what a double-click did
 * when the first press had already moved the date), the consent gate (no saved-token charge without a stored consent), the ledger
 * row opened BEFORE the gateway call, the retry policy, and the post-success state +
 * order materialization. The admin is just another caller of the same proven path.
 */
final class ChargeNowService
{
    public function __construct(private readonly ChargeOrchestrator $orchestrator) {}

    /**
     * @param  bool  $repeatApproved  the admin EXPLICITLY approved charging a
     *                                subscription that was already charged in the
     *                                last day. Only ever true from a confirmation a
     *                                person ticked — never defaulted, never inferred.
     */
    public function chargeNow(InstallmentPlan $plan, bool $repeatApproved = false): ChargeOutcome
    {
        return $this->orchestrator->charge(
            (int) $plan->getKey(),
            $plan->isRecurring() ? PaymentType::RECURRING : PaymentType::INSTALLMENT,
            $repeatApproved,
        );
    }
}
