<?php

namespace App\Mail;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;

/**
 * Plan-cancellation confirmation. Sent by the cancellation flow's listener.
 * Ported from the reference engine's PlanCancelledMail.
 *
 * cancellation_reason is an optional template extra.
 */
final class PlanCancelledMail extends PlanMail
{
    // === CONSTANTS ===
    public function __construct(
        Shop $shop,
        InstallmentPlan $plan,
        ?InstallmentPayment $payment = null,
        ?string $portalUrl = null,
        public readonly ?string $cancellationReason = null,
    ) {
        parent::__construct($shop, $plan, $payment, $portalUrl);
    }

    protected function templateKey(): string
    {
        return MerchantMailSettings::TEMPLATE_PLAN_CANCELLED;
    }

    protected function extraVars(): array
    {
        return [
            'cancellation_reason' => $this->cancellationReason ?? '',
        ];
    }
}
