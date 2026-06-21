<?php

namespace App\Support;

use App\Mail\Support\TemplateRenderer;
use App\Models\MerchantMailSettings;

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
