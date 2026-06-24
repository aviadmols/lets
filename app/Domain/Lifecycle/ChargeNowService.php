<?php

namespace App\Domain\Lifecycle;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOutcome;

/**
 * Out-of-schedule "Charge now" (admin trigger). Deliberately a THIN wrapper over the
 * ChargeOrchestrator so it inherits every money-safety law unchanged: the row lock,
 * the idempotent short-circuit (a double-click collapses to ONE charge on the same
 * key), the consent gate (no saved-token charge without a stored consent), the ledger
 * row opened BEFORE the gateway call, the retry policy, and the post-success state +
 * order materialization. The admin is just another caller of the same proven path.
 */
final class ChargeNowService
{
    public function __construct(private readonly ChargeOrchestrator $orchestrator) {}

    public function chargeNow(InstallmentPlan $plan): ChargeOutcome
    {
        return $this->orchestrator->charge(
            (int) $plan->getKey(),
            $plan->isRecurring() ? PaymentType::RECURRING : PaymentType::INSTALLMENT,
        );
    }
}
