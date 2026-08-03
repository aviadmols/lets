<?php

namespace App\Support;

use App\Mail\Support\TemplateRenderer;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;

/**
 * Renders a SAFE preview of an email template for the admin settings page +
 * Timeline email previews. The admin agent consumes the result and drops it into
 * an isolated iframe via `srcdoc` (the preview HTML is htmlspecialchars-escaped at
 * the VIEW layer before it enters srcdoc, so the iframe shows the markup without
 * executing it inside the admin origin).
 *
 * Preview substitution uses the SAME strtr path as production (TemplateRenderer),
 * fed SAMPLE vars — so a merchant sees exactly what strtr will produce, and a
 * body containing `{{ 7*7 }}` / `@php` previews as inert literal text, never
 * evaluated. The merchant's custom body is previewed when set; otherwise the
 * platform default.
 *
 * Ported from the reference engine's Support/EmailPreviewRenderer (3 preview
 * modes), trimmed to the per-shop settings surface.
 */
final class EmailPreviewRenderer
{
    // === CONSTANTS — sample values that do not depend on language ===
    private const SAMPLE = [
        'customer_email' => 'dana@example.com',
        'amount' => '149.00',
        'currency' => 'ILS',
        'plan_id' => '1042',
        'installment_count' => '6',
        'installment_sequence' => '2',
        'next_charge_date' => '15/07/2026',
        'next_charge_date_he' => '15/07/2026',
        'next_retry_date' => '18/06/2026',
        'due_date' => '20/06/2026',
        'portal_url' => 'https://app.lets.co.il/portal/sample',
        'invoice_url' => 'https://app.lets.co.il/invoice/sample',
        'code' => '482013',
        'expires_minutes' => '10',
    ];

    /**
     * Sample values that DO depend on language.
     *
     * A merchant previewing their English template should not be shown a Hebrew
     * customer name and a Hebrew decline reason inside otherwise-English copy —
     * that is precisely the mismatch the language switch exists to reveal.
     *
     * @return array<string, string>
     */
    private static function localisedSample(): array
    {
        return [
            'customer_name' => __('mail.sample.customer_name'),
            'business_name' => __('mail.sample.business_name'),
            'product_title' => __('mail.sample.product_title'),
            'failure_reason' => __('mail.sample.failure_reason'),
            'cancellation_reason' => __('mail.sample.cancellation_reason'),
            // The two ready-made sentences, built the same way production builds
            // them, so the preview shows the real string and not a hand-typed
            // approximation that can drift.
            'installment_progress' => TemplateRenderer::progress('2', '6'),
            'installment_total_note' => sprintf((string) __('mail_default.total_note'), '6'),
        ];
    }

    /**
     * Render the preview HTML (subject + body) for a template, using either the
     * shop's custom copy (when set) or the platform default.
     *
     * @return array{subject: string, html: string, is_custom: bool}
     */
    public static function preview(string $template, ?MerchantMailSettings $settings = null, ?string $locale = null): array
    {
        // The locale is bound around the WHOLE render, not passed down: the
        // default copy, the reading direction and the "(payment X of Y)" aside
        // all resolve from it, exactly as they do on the real send.
        return self::inLocale($locale ?? self::localeFor($settings), static function () use ($template, $settings): array {
            $vars = self::sampleVarsFor($template);

            $customSubject = $settings?->customSubject($template);
            $customBody = $settings?->customBody($template);

            $subjectTemplate = $customSubject ?? DefaultEmailTemplates::subject($template);
            $bodyTemplate = $customBody ?? DefaultEmailTemplates::body($template);

            return [
                // strtr — identical to the production substitution path. No Blade.
                'subject' => TemplateRenderer::render($subjectTemplate, $vars),
                'html' => TemplateRenderer::render($bodyTemplate, $vars),
                'is_custom' => $customBody !== null,
            ];
        });
    }

    /**
     * The language THIS SHOP'S customers read.
     *
     * Callers usually hand over a plan and a shop rather than the settings row —
     * the Timeline preview does — so falling back to the bound tenant's own
     * setting is what keeps a preview in the same language as the real send.
     * Null means "leave the current locale alone", which is right for a caller
     * with no shop context at all.
     */
    private static function localeFor(?MerchantMailSettings $settings): ?string
    {
        if ($settings !== null) {
            return $settings->emailLocale();
        }

        return Tenant::check() ? MerchantMailSettings::current()->emailLocale() : null;
    }

    /** Run a render with a locale bound, restoring whatever was in force. */
    private static function inLocale(?string $locale, callable $callback): mixed
    {
        if ($locale === null) {
            return $callback();
        }

        $previous = app()->getLocale();

        try {
            app()->setLocale($locale);

            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * The same preview, but for a REAL subscription: this customer's name, their
     * product, their amount.
     *
     * The settings page previews a template nobody has received yet, so sample
     * values are right there. On a plan's Timeline they are wrong — a merchant
     * checking what a customer was told must not be shown a stranger's name.
     *
     * Honest about its limits. This is the CURRENT template filled with the plan's
     * CURRENT details, not an archived copy of the message: a template edited since
     * the send, or a plan whose next charge date has moved on, previews as it
     * stands today. The event's own details (the amount charged, the sequence, the
     * failure reason) are layered on top, because those are the facts of the send
     * itself and the plan no longer carries them.
     *
     * Anything that cannot be recovered renders EMPTY rather than as a sample. A
     * blank is a gap the merchant can see; a plausible fake is one they cannot.
     *
     * @param  array<string, mixed>  $eventDetails  the Timeline event's `details`
     * @return array{subject: string, html: string, is_custom: bool}
     */
    public static function forPlan(
        string $template,
        InstallmentPlan $plan,
        ?Shop $shop = null,
        array $eventDetails = [],
        ?MerchantMailSettings $settings = null,
    ): array {
        // Same locale binding as preview(): a merchant checking what a customer
        // was told must see it in the language the customer received it in.
        return self::inLocale(self::localeFor($settings), static function () use ($template, $plan, $shop, $eventDetails, $settings): array {
            $vars = self::planVarsFor($template, $plan, $shop, $eventDetails, $settings);

            $customSubject = $settings?->customSubject($template);
            $customBody = $settings?->customBody($template);

            return [
                'subject' => TemplateRenderer::render($customSubject ?? DefaultEmailTemplates::subject($template), $vars),
                'html' => TemplateRenderer::render($customBody ?? DefaultEmailTemplates::body($template), $vars),
                'is_custom' => $customBody !== null,
            ];
        });
    }

    /**
     * The var bag for one plan, scoped to the placeholders this template uses.
     *
     * @param  array<string, mixed>  $eventDetails
     * @return array<string, string>
     */
    public static function planVarsFor(
        string $template,
        InstallmentPlan $plan,
        ?Shop $shop = null,
        array $eventDetails = [],
        ?MerchantMailSettings $settings = null,
    ): array {
        // The production var builder — the preview and the send agree by
        // construction, not by two lists that drift apart.
        //
        // The shop's portal PAGE, deliberately not a freshly signed magic link: a
        // preview should not mint a live credential into an admin screen, and a
        // link minted now is not the one the customer received anyway.
        $vars = TemplateRenderer::planVars(
            plan: $plan,
            businessName: BusinessName::for($shop),
            payment: null,
            portalUrl: $settings?->portal_store_page_url ?: null,
            invoiceUrl: null,
        );

        $vars = array_merge($vars, self::fromEventDetails($eventDetails, $vars));

        // Recompute the "(payment X of Y)" aside: the sequence only became known
        // when the event's details were layered on, and the line built during
        // planVars() was made without it.
        $vars['installment_progress'] = TemplateRenderer::progress(
            (string) ($vars['installment_sequence'] ?? ''),
            (string) ($vars['installment_count'] ?? ''),
        );

        $placeholders = DefaultEmailTemplates::placeholders($template);
        if ($placeholders === []) {
            return array_map(static fn ($v): string => (string) $v, $vars);
        }

        $scoped = [];
        foreach ($placeholders as $key) {
            // '' — never SAMPLE. An unrecoverable value shows as a gap, not as
            // someone else's details.
            $scoped[$key] = (string) ($vars[$key] ?? '');
        }

        return $scoped;
    }

    /**
     * What the send itself recorded, which the plan cannot tell us later: the
     * amount that was actually charged, which cycle it was, and why it failed or
     * was cancelled.
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $base
     * @return array<string, string>
     */
    private static function fromEventDetails(array $details, array $base): array
    {
        $overlay = [];

        foreach (['amount', 'currency'] as $key) {
            $value = trim((string) ($details[$key] ?? ''));
            if ($value !== '') {
                $overlay[$key] = $value;
            }
        }

        $sequence = trim((string) ($details['sequence'] ?? ''));
        if ($sequence !== '') {
            $overlay['installment_sequence'] = $sequence;
        }

        // One recorded `reason`, two templates that name it differently.
        $reason = trim((string) ($details['reason'] ?? ''));
        if ($reason !== '') {
            $overlay['failure_reason'] = $reason;
            $overlay['cancellation_reason'] = $reason;
        }

        // A retry and a due date are both "the next time money moves", which is
        // what the plan still holds.
        $nextCharge = trim((string) ($base['next_charge_date'] ?? ''));
        if ($nextCharge !== '') {
            $overlay['next_retry_date'] = $nextCharge;
            $overlay['due_date'] = $nextCharge;
        }

        return $overlay;
    }

    /**
     * The sample var bag scoped to the placeholders a template actually supports
     * (so the helper text + preview line up).
     *
     * @return array<string, string>
     */
    public static function sampleVarsFor(string $template): array
    {
        $sample = array_merge(self::SAMPLE, self::localisedSample());
        $placeholders = DefaultEmailTemplates::placeholders($template);

        if ($placeholders === []) {
            return $sample;
        }

        $vars = [];
        foreach ($placeholders as $key) {
            $vars[$key] = $sample[$key] ?? '';
        }

        return $vars;
    }
}
