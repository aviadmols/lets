<?php

namespace App\Mail;

use App\Mail\Support\TemplateRenderer;
use App\Models\MerchantMailSettings;

/**
 * Per-charge confirmation, sent on every SUCCESSFUL non-first charge (the first
 * charge gets FirstPaymentWelcomeMail instead). Shows the installment position
 * and links the invoice + portal. Fired by the ChargeSucceeded listener.
 */
final class ChargeSucceededMail extends PlanMail
{
    // === CONSTANTS ===
    protected function templateKey(): string
    {
        return MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED;
    }

    protected function extraVars(): array
    {
        // The sequence is the count of succeeded slots; the payment row carries
        // its own sequence when present.
        $sequence = $this->payment?->sequence
            ?? TemplateRenderer::succeededSequence($this->plan);

        return [
            'installment_sequence' => (string) $sequence,
        ];
    }
}
