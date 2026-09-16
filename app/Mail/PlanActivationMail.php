<?php

namespace App\Mail;

use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;

/**
 * "Your subscription is ready — start it when you are."
 *
 * TRANSACTIONAL: the customer bought a subscription that waits for them, and this is
 * the only way in. It rides PlanMail, so the merchant's own copy wins when they wrote
 * some, substitution is strtr() and never Blade, and it reads in the SHOPPER's language.
 */
final class PlanActivationMail extends PlanMail
{
    public function __construct(
        Shop $shop,
        InstallmentPlan $plan,
        public readonly string $activationUrl,
    ) {
        parent::__construct($shop, $plan);
    }

    protected function templateKey(): string
    {
        return MerchantMailSettings::TEMPLATE_PLAN_ACTIVATION;
    }

    protected function extraVars(): array
    {
        return ['activation_url' => $this->activationUrl];
    }
}
