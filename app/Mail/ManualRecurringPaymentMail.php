<?php

namespace App\Mail;

use App\Models\MerchantMailSettings;

/**
 * Manual-payment-mode invoice email: a plan with no saved token gets a draft
 * invoice link to pay each cycle instead of an auto-charge. The orchestrator's
 * manual-mode short-circuit dispatches this (and guards re-sends via
 * meta.manual_payment_sent_at). Ported from the reference engine's
 * ManualRecurringPaymentMail.
 *
 * invoice_url is the merchant's draft-order invoice (set by the caller).
 * due_date is an optional template extra.
 */
final class ManualRecurringPaymentMail extends PlanMail
{
    // === CONSTANTS ===
    protected function templateKey(): string
    {
        return MerchantMailSettings::TEMPLATE_MANUAL_RECURRING_PAYMENT;
    }

    protected function extraVars(): array
    {
        return [
            'due_date' => $this->plan->next_charge_at?->format('d/m/Y') ?? '',
        ];
    }
}
