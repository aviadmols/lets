<?php

namespace App\Mail;

use App\Models\MerchantMailSettings;

/**
 * Sent once, after the FIRST successful charge on a plan. Welcomes the customer
 * and confirms the plan terms. Ported from the reference engine's
 * FirstPaymentWelcomeMail.
 */
final class FirstPaymentWelcomeMail extends PlanMail
{
    // === CONSTANTS ===
    protected function templateKey(): string
    {
        return MerchantMailSettings::TEMPLATE_FIRST_PAYMENT_WELCOME;
    }
}
