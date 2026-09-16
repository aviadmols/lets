<?php

namespace App\Mail;

use App\Mail\Concerns\UsesCustomMailTemplate;
use App\Mail\Support\TemplateRenderer;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The activation link for a subscription Shopify Payments bills.
 *
 * The SAME template as the PayPlus rail's PlanActivationMail — the merchant edits one
 * "activation link" email, not one per engine. Only the variable bag comes from a
 * contract instead of a plan. strtr, never Blade, in the shopper's language.
 */
final class ContractActivationMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesCustomMailTemplate;

    // === CONSTANTS ===
    private const TEMPLATE = MerchantMailSettings::TEMPLATE_PLAN_ACTIVATION;

    public function __construct(
        public readonly Shop $shop,
        public readonly SubscriptionContract $contract,
        public readonly string $activationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return $this->inMailLocale($this->shop, fn (): Envelope => $this->buildEnvelope(self::TEMPLATE, $this->shop, $this->vars()));
    }

    public function content(): Content
    {
        return $this->inMailLocale($this->shop, fn (): Content => $this->buildContent(self::TEMPLATE, $this->shop, $this->vars()));
    }

    /** @return array<string, scalar|null> */
    private function vars(): array
    {
        return TemplateRenderer::contractVars($this->contract, $this->resolveBusinessName($this->shop))
            + ['activation_url' => $this->activationUrl];
    }
}
