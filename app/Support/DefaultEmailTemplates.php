<?php

namespace App\Support;

use App\Models\MerchantMailSettings;

/**
 * Platform-default email subject + HTML body for each notification template, plus
 * the placeholder catalogue per template (for the settings-page helper text and
 * the preview surface).
 *
 * These defaults are the FALLBACK: a merchant who has not overridden a template
 * gets this copy. The HTML here is the ONE allowed exception to the no-inline-CSS
 * rule — email clients strip <style>, so every visual rule is inlined. The bodies
 * are RTL-first (dir="rtl"), matching the reference engine's Hebrew-merchant look.
 *
 * Placeholders are written as {snake_case} tokens and substituted by
 * App\Mail\Support\TemplateRenderer via strtr() — NEVER Blade. The token set per
 * template is exactly the keys TemplateRenderer puts in the var bag for that mail.
 *
 * Ported from the reference engine's Support/DefaultEmailTemplates (single-tenant
 * → per-shop: the copy is identical; the values are filled from the sending
 * shop's plan/payment/business name at send time).
 */
final class DefaultEmailTemplates
{
    // === CONSTANTS ===
    /** Shared inline-CSS card shell wrapped around each template's body. */
    private const CARD_OPEN = '<div dir="rtl" style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1f2937;background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;">';
    private const CARD_CLOSE = '</div>';
    private const H1 = 'style="font-size:20px;font-weight:700;margin:0 0 16px;color:#111827;"';
    private const P = 'style="font-size:15px;line-height:1.6;margin:0 0 14px;"';
    private const AMOUNT = 'style="font-size:15px;line-height:1.6;margin:0 0 14px;font-weight:700;"';
    private const CTA = 'style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-size:15px;font-weight:600;margin:8px 0 16px;"';
    private const MUTED = 'style="font-size:12px;line-height:1.5;color:#6b7280;margin:18px 0 0;border-top:1px solid #e5e7eb;padding-top:14px;"';

    /**
     * Placeholders available per template (for UI helper text + sample vars).
     *
     * @var array<string, list<string>>
     */
    private const PLACEHOLDERS = [
        MerchantMailSettings::TEMPLATE_FIRST_PAYMENT_WELCOME => [
            'customer_name', 'business_name', 'product_title', 'amount', 'currency',
            'installment_count', 'plan_id', 'portal_url', 'next_charge_date',
        ],
        MerchantMailSettings::TEMPLATE_RECURRING_PAYMENT_REMINDER => [
            'customer_name', 'business_name', 'product_title', 'amount', 'currency',
            'next_charge_date', 'portal_url', 'plan_id',
        ],
        MerchantMailSettings::TEMPLATE_MANUAL_RECURRING_PAYMENT => [
            'customer_name', 'business_name', 'product_title', 'amount', 'currency',
            'invoice_url', 'due_date', 'plan_id',
        ],
        MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED => [
            'customer_name', 'business_name', 'product_title', 'amount', 'currency',
            'installment_sequence', 'installment_count', 'invoice_url', 'portal_url', 'plan_id',
        ],
        MerchantMailSettings::TEMPLATE_CHARGE_FAILED => [
            'customer_name', 'business_name', 'product_title', 'amount', 'currency',
            'failure_reason', 'next_retry_date', 'portal_url', 'plan_id',
        ],
        MerchantMailSettings::TEMPLATE_PLAN_CANCELLED => [
            'customer_name', 'business_name', 'product_title', 'plan_id',
            'cancellation_reason', 'portal_url',
        ],
    ];

    /**
     * Default subject line per template. Plain text; {tokens} allowed and
     * strtr-substituted exactly like the body.
     *
     * @var array<string, string>
     */
    private const SUBJECTS = [
        MerchantMailSettings::TEMPLATE_FIRST_PAYMENT_WELCOME => 'ברוכים הבאים — התשלום הראשון התקבל ({business_name})',
        MerchantMailSettings::TEMPLATE_RECURRING_PAYMENT_REMINDER => 'תזכורת: חיוב קרוב בתאריך {next_charge_date} ({business_name})',
        MerchantMailSettings::TEMPLATE_MANUAL_RECURRING_PAYMENT => 'בקשת תשלום — {business_name}',
        MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED => 'התשלום בסך {amount} {currency} התקבל ({business_name})',
        MerchantMailSettings::TEMPLATE_CHARGE_FAILED => 'החיוב נכשל — נדרשת פעולה ({business_name})',
        MerchantMailSettings::TEMPLATE_PLAN_CANCELLED => 'התוכנית בוטלה — {business_name}',
    ];

    /** Default subject for a template ({tokens} still get strtr-substituted). */
    public static function subject(string $template): string
    {
        return self::SUBJECTS[$template] ?? '{business_name}';
    }

    /** Default HTML body for a template (inline CSS, RTL, {token} placeholders). */
    public static function body(string $template): string
    {
        return match ($template) {
            MerchantMailSettings::TEMPLATE_FIRST_PAYMENT_WELCOME => self::firstPaymentWelcome(),
            MerchantMailSettings::TEMPLATE_RECURRING_PAYMENT_REMINDER => self::recurringReminder(),
            MerchantMailSettings::TEMPLATE_MANUAL_RECURRING_PAYMENT => self::manualRecurring(),
            MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED => self::chargeSucceeded(),
            MerchantMailSettings::TEMPLATE_CHARGE_FAILED => self::chargeFailed(),
            MerchantMailSettings::TEMPLATE_PLAN_CANCELLED => self::planCancelled(),
            default => self::CARD_OPEN.'<p '.self::P.'>{business_name}</p>'.self::CARD_CLOSE,
        };
    }

    /** The placeholders available in a given template (UI helper text + samples). */
    public static function placeholders(string $template): array
    {
        return self::PLACEHOLDERS[$template] ?? [];
    }

    /** The Blade view used to render this template's DEFAULT (non-custom) body. */
    public static function defaultView(string $template): string
    {
        return 'emails.'.str_replace('_', '-', $template);
    }

    // === Default bodies (inline CSS — the allowed email exception) ===

    private static function firstPaymentWelcome(): string
    {
        return self::CARD_OPEN
            .'<h1 '.self::H1.'>שלום {customer_name},</h1>'
            .'<p '.self::P.'>תודה! התשלום הראשון עבור <strong>{product_title}</strong> התקבל בהצלחה.</p>'
            .'<p '.self::AMOUNT.'>סכום: {amount} {currency}</p>'
            .'<p '.self::P.'>סך הכל {installment_count} תשלומים בתוכנית. החיוב הבא צפוי בתאריך {next_charge_date}.</p>'
            .'<a href="{portal_url}" '.self::CTA.'>צפייה בתוכנית שלי</a>'
            .'<p '.self::MUTED.'>מספר תוכנית #{plan_id} · {business_name}</p>'
            .self::CARD_CLOSE;
    }

    private static function recurringReminder(): string
    {
        return self::CARD_OPEN
            .'<h1 '.self::H1.'>שלום {customer_name},</h1>'
            .'<p '.self::P.'>זוהי תזכורת שהחיוב הבא עבור <strong>{product_title}</strong> צפוי בתאריך <strong>{next_charge_date}</strong>.</p>'
            .'<p '.self::AMOUNT.'>סכום: {amount} {currency}</p>'
            .'<a href="{portal_url}" '.self::CTA.'>ניהול המנוי שלי</a>'
            .'<p '.self::MUTED.'>מספר תוכנית #{plan_id} · {business_name}</p>'
            .self::CARD_CLOSE;
    }

    private static function manualRecurring(): string
    {
        return self::CARD_OPEN
            .'<h1 '.self::H1.'>שלום {customer_name},</h1>'
            .'<p '.self::P.'>הגיע מועד התשלום עבור <strong>{product_title}</strong>. נא להשלים את התשלום עד {due_date}.</p>'
            .'<p '.self::AMOUNT.'>סכום לתשלום: {amount} {currency}</p>'
            .'<a href="{invoice_url}" '.self::CTA.'>תשלום עכשיו</a>'
            .'<p '.self::MUTED.'>מספר תוכנית #{plan_id} · {business_name}</p>'
            .self::CARD_CLOSE;
    }

    private static function chargeSucceeded(): string
    {
        return self::CARD_OPEN
            .'<h1 '.self::H1.'>שלום {customer_name},</h1>'
            .'<p '.self::P.'>התשלום עבור <strong>{product_title}</strong> התקבל בהצלחה (תשלום {installment_sequence} מתוך {installment_count}).</p>'
            .'<p '.self::AMOUNT.'>סכום: {amount} {currency}</p>'
            .'<a href="{invoice_url}" '.self::CTA.'>צפייה בחשבונית</a>'
            .'<p '.self::P.'><a href="{portal_url}" style="color:#2563eb;">ניהול התוכנית שלי</a></p>'
            .'<p '.self::MUTED.'>מספר תוכנית #{plan_id} · {business_name}</p>'
            .self::CARD_CLOSE;
    }

    private static function chargeFailed(): string
    {
        return self::CARD_OPEN
            .'<h1 '.self::H1.'>שלום {customer_name},</h1>'
            .'<p '.self::P.'>לא הצלחנו לחייב את אמצעי התשלום עבור <strong>{product_title}</strong>.</p>'
            .'<p '.self::AMOUNT.'>סכום: {amount} {currency}</p>'
            .'<p '.self::P.'>סיבה: {failure_reason}. ננסה שוב בתאריך {next_retry_date}. ניתן לעדכן את אמצעי התשלום מראש:</p>'
            .'<a href="{portal_url}" '.self::CTA.'>עדכון אמצעי תשלום</a>'
            .'<p '.self::MUTED.'>מספר תוכנית #{plan_id} · {business_name}</p>'
            .self::CARD_CLOSE;
    }

    private static function planCancelled(): string
    {
        return self::CARD_OPEN
            .'<h1 '.self::H1.'>שלום {customer_name},</h1>'
            .'<p '.self::P.'>התוכנית עבור <strong>{product_title}</strong> בוטלה. {cancellation_reason}</p>'
            .'<a href="{portal_url}" '.self::CTA.'>צפייה בהיסטוריה</a>'
            .'<p '.self::MUTED.'>מספר תוכנית #{plan_id} · {business_name}</p>'
            .self::CARD_CLOSE;
    }
}
