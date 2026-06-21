<?php

namespace App\Mail;

use App\Mail\Concerns\UsesCustomMailTemplate;
use App\Mail\Support\TemplateRenderer;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Base for every plan-driven notification mail. Resolves the SENDING shop's
 * MerchantMailSettings, picks custom-vs-default copy, and renders via strtr (NOT
 * Blade) — all shared in UsesCustomMailTemplate. Subclasses only declare their
 * template key + any template-specific extra vars (failure_reason, etc.).
 *
 * Tenant-safe: the Shop is carried EXPLICITLY (never inferred from global state),
 * so a queued mail serialized for shop A always renders shop A's templates even
 * when run on a worker that just handled shop B.
 *
 * Ported from the reference engine's per-template mailables, collapsed onto one
 * base so the strtr-not-Blade rule lives in exactly one place.
 */
abstract class PlanMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesCustomMailTemplate;

    public function __construct(
        public readonly Shop $shop,
        public readonly InstallmentPlan $plan,
        public readonly ?InstallmentPayment $payment = null,
        public readonly ?string $portalUrl = null,
        public readonly ?string $invoiceUrl = null,
    ) {}

    /** The MerchantMailSettings template key this mail renders. */
    abstract protected function templateKey(): string;

    /**
     * Template-specific extra vars merged on top of the shared plan var bag
     * (e.g. failure_reason, next_retry_date, due_date, cancellation_reason).
     *
     * @return array<string, scalar|null>
     */
    protected function extraVars(): array
    {
        return [];
    }

    /** The complete strtr var bag for this mail (shared + extras). */
    final protected function vars(): array
    {
        return array_merge(
            TemplateRenderer::planVars(
                plan: $this->plan,
                businessName: $this->resolveBusinessName($this->shop),
                payment: $this->payment,
                portalUrl: $this->portalUrl,
                invoiceUrl: $this->invoiceUrl,
            ),
            $this->extraVars(),
        );
    }

    public function envelope(): Envelope
    {
        return $this->buildEnvelope($this->templateKey(), $this->shop, $this->vars());
    }

    public function content(): Content
    {
        return $this->buildContent($this->templateKey(), $this->shop, $this->vars());
    }
}
