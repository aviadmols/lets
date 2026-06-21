<?php

namespace App\Mail\Concerns;

use App\Mail\Support\MailSettingsConfigurator;
use App\Mail\Support\TemplateRenderer;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Support\DefaultEmailTemplates;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Shared mailable behaviour: pick the merchant's custom subject/body when set,
 * else the platform default; render through TemplateRenderer (strtr, NEVER
 * Blade); and apply the per-shop SMTP override.
 *
 * Ported + multi-tenant-refactored from the reference engine's
 * Mail/UsesCustomMailTemplate. Single-tenant → per-shop: the settings row is the
 * SENDING shop's MerchantMailSettings::current() and the SMTP override comes off
 * that same row, so two shops sending on the same worker never cross From
 * addresses or mailbox credentials.
 *
 * A using mailable provides templateKey(), the Shop, and the rendered var bag.
 */
trait UsesCustomMailTemplate
{
    use ResolvesBusinessName;

    /**
     * Build the Envelope: subject = merchant custom subject (strtr-rendered) or
     * the default, From = the shop's mailbox/name when an SMTP override is on,
     * else the platform default From.
     *
     * @param array<string, scalar|null> $vars
     */
    protected function buildEnvelope(string $templateKey, Shop $shop, array $vars): Envelope
    {
        $settings = $this->mailSettings($shop);

        $subjectTemplate = $settings?->customSubject($templateKey)
            ?? DefaultEmailTemplates::subject($templateKey);

        $envelope = new Envelope(
            subject: TemplateRenderer::render($subjectTemplate, $vars),
        );

        // Per-shop From, only when the merchant runs their own SMTP + From.
        if ($settings?->override_env_smtp && $settings->from_address) {
            $envelope = $envelope->from(
                $settings->from_address,
                $settings->from_name ?: $this->resolveBusinessName($shop),
            );
        }

        return $envelope;
    }

    /**
     * Build the Content: when the merchant has a custom HTML body use it via
     * TemplateRenderer (strtr) wrapped in the safe pass-through view; else render
     * the default Blade view for the template. NB: the default view is a TRUSTED
     * platform template — Blade is fine there. Merchant HTML never touches Blade.
     *
     * @param array<string, scalar|null> $vars
     */
    protected function buildContent(string $templateKey, Shop $shop, array $vars): Content
    {
        $settings = $this->mailSettings($shop);
        $customBody = $settings?->customBody($templateKey);

        if ($customBody !== null) {
            // Merchant HTML: strtr-substituted, then handed to a wrapper view that
            // ONLY echoes the already-rendered string ({!! $renderedHtml !!}). No
            // Blade compilation of merchant input happens anywhere on this path.
            return new Content(
                view: 'emails.user-template-wrapper',
                with: [
                    'renderedHtml' => TemplateRenderer::render($customBody, $vars),
                    'businessName' => $this->resolveBusinessName($shop),
                ],
            );
        }

        // Platform default: a trusted Blade view receives the SAME var bag.
        return new Content(
            view: DefaultEmailTemplates::defaultView($templateKey),
            with: array_merge($vars, [
                'businessName' => $this->resolveBusinessName($shop),
            ]),
        );
    }

    /**
     * The sending shop's settings row, or null if none. Keyed EXPLICITLY by the
     * mailable's own shop id and read via the audited cross-tenant query so the
     * lookup is correct even when this mailable is rendered without a bound tenant
     * (e.g. on a queue worker) — it can only ever return THIS shop's own row.
     */
    protected function mailSettings(Shop $shop): ?MerchantMailSettings
    {
        return MerchantMailSettings::acrossAllTenants()
            ->where('shop_id', $shop->getKey())
            ->first();
    }

    /**
     * Merge the sending shop's SMTP override into the live mail config for THIS
     * send. Thin delegator to MailSettingsConfigurator so callers can use the
     * canonical helper name from the reference engine. When override_env_smtp is
     * false this is a no-op and the platform .env mailer is used. Per-shop,
     * runtime-scoped — never persisted into the shared config.
     */
    public static function mergeMailSettingsIntoConfig(Shop $shop): void
    {
        MailSettingsConfigurator::apply($shop);
    }
}
