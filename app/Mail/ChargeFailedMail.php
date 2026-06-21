<?php

namespace App\Mail;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;

/**
 * Failed-charge notice: the saved token could not be charged. Tells the customer
 * the reason + the next retry date and links the portal to fix the card. Fired by
 * the ChargeFailed listener. Ported from the reference engine's failed-charge
 * mail.
 *
 * failure_reason / next_retry_date are template extras carried explicitly (a
 * failed charge has no succeeded payment value to derive them from).
 */
final class ChargeFailedMail extends PlanMail
{
    // === CONSTANTS ===
    public function __construct(
        Shop $shop,
        InstallmentPlan $plan,
        ?InstallmentPayment $payment = null,
        ?string $portalUrl = null,
        public readonly ?string $failureReason = null,
        public readonly ?string $nextRetryDate = null,
    ) {
        parent::__construct($shop, $plan, $payment, $portalUrl);
    }

    protected function templateKey(): string
    {
        return MerchantMailSettings::TEMPLATE_CHARGE_FAILED;
    }

    protected function extraVars(): array
    {
        return [
            'failure_reason' => $this->failureReason ?? '',
            'next_retry_date' => $this->nextRetryDate ?? '',
        ];
    }
}
