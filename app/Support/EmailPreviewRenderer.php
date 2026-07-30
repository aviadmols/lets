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
    // === CONSTANTS — sample values for every placeholder (preview only) ===
    private const SAMPLE = [
        'customer_name' => 'דנה כהן',
        'customer_email' => 'dana@example.com',
        'business_name' => 'החנות שלי',
        'product_title' => 'מנוי חודשי',
        'amount' => '149.00',
        'currency' => 'ILS',
        'plan_id' => '1042',
        'installment_count' => '6',
        'installment_sequence' => '2',
        // The two ready-made sentences. They are vars and not template
        // conditionals because an open-ended subscription must show neither —
        // see TemplateRenderer::PROGRESS_FORMAT.
        'installment_progress' => ' (תשלום 2 מתוך 6)',
        'installment_total_note' => 'סך הכל 6 תשלומים בתוכנית. ',
        'next_charge_date' => '15/07/2026',
        'next_charge_date_he' => '15/07/2026',
        'next_retry_date' => '18/06/2026',
        'due_date' => '20/06/2026',
        'portal_url' => 'https://app.lets.co.il/portal/sample',
        'invoice_url' => 'https://app.lets.co.il/invoice/sample',
        'failure_reason' => 'הכרטיס נדחה (אין כיסוי)',
        'cancellation_reason' => 'התוכנית בוטלה לבקשתך.',
    ];

    /**
     * Render the preview HTML (subject + body) for a template, using either the
     * shop's custom copy (when set) or the platform default.
     *
     * @return array{subject: string, html: string, is_custom: bool}
     */
    public static function preview(string $template, ?MerchantMailSettings $settings = null): array
    {
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
        $vars = self::planVarsFor($template, $plan, $shop, $eventDetails, $settings);

        $customSubject = $settings?->customSubject($template);
        $customBody = $settings?->customBody($template);

        return [
            'subject' => TemplateRenderer::render($customSubject ?? DefaultEmailTemplates::subject($template), $vars),
            'html' => TemplateRenderer::render($customBody ?? DefaultEmailTemplates::body($template), $vars),
            'is_custom' => $customBody !== null,
        ];
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
        $placeholders = DefaultEmailTemplates::placeholders($template);

        if ($placeholders === []) {
            return self::SAMPLE;
        }

        $vars = [];
        foreach ($placeholders as $key) {
            $vars[$key] = self::SAMPLE[$key] ?? '';
        }

        return $vars;
    }
}
