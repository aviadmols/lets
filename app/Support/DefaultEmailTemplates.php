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
 * rule — email clients strip <style>, so every visual rule is inlined.
 *
 * THE COPY LIVES IN lang/{en,he}/mail_default.php, not in this file. Two reasons:
 * the merchant chooses which language their CUSTOMERS read (Settings → Email), and
 * the same words have to serve both the send and the admin preview. This class
 * assembles them into HTML; it does not author them. Direction and alignment come
 * from the locale at render time, so an English shop does not send RTL mail.
 *
 * Placeholders are written as {snake_case} tokens and substituted by
 * App\Mail\Support\TemplateRenderer via strtr() — NEVER Blade. The token set per
 * template is exactly the keys TemplateRenderer puts in the var bag for that mail.
 */
final class DefaultEmailTemplates
{
    // === CONSTANTS ===
    /** Locales the platform ships default copy in. */
    public const LOCALE_HE = 'he';
    public const LOCALE_EN = 'en';
    public const LOCALES = [self::LOCALE_HE, self::LOCALE_EN];

    private const RTL_LOCALES = ['he', 'ar'];

    private const H1 = 'style="font-size:20px;font-weight:700;margin:0 0 16px;color:#111827;"';
    private const P = 'style="font-size:15px;line-height:1.6;margin:0 0 14px;"';
    private const AMOUNT = 'style="font-size:15px;line-height:1.6;margin:0 0 14px;font-weight:700;"';
    private const CTA = 'style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-size:15px;font-weight:600;margin:8px 0 16px;"';
    private const MUTED = 'style="font-size:12px;line-height:1.5;color:#6b7280;margin:18px 0 0;border-top:1px solid #e5e7eb;padding-top:14px;"';
    /** The sign-in digits: big, monospaced, and LTR even inside an RTL card. */
    private const CODE = 'style="direction:ltr;unicode-bidi:isolate;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:34px;font-weight:700;letter-spacing:8px;margin:8px 0 18px;color:#111827;"';

    /**
     * Placeholders available per template (for UI helper text + sample vars).
     *
     * @var array<string, list<string>>
     */
    private const PLACEHOLDERS = [
        MerchantMailSettings::TEMPLATE_FIRST_PAYMENT_WELCOME => [
            'customer_name', 'business_name', 'product_title', 'amount', 'currency',
            'installment_count', 'installment_total_note', 'plan_id', 'portal_url', 'next_charge_date',
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
            'installment_sequence', 'installment_count', 'installment_progress', 'invoice_url', 'portal_url', 'plan_id',
        ],
        MerchantMailSettings::TEMPLATE_CHARGE_FAILED => [
            'customer_name', 'business_name', 'product_title', 'amount', 'currency',
            'failure_reason', 'next_retry_date', 'portal_url', 'plan_id',
        ],
        MerchantMailSettings::TEMPLATE_PLAN_CANCELLED => [
            'customer_name', 'business_name', 'product_title', 'plan_id',
            'cancellation_reason', 'portal_url',
        ],
        MerchantMailSettings::TEMPLATE_LOGIN_CODE => [
            'code', 'expires_minutes', 'business_name',
        ],
        MerchantMailSettings::TEMPLATE_ORDER_UPDATED => [
            'customer_name', 'business_name', 'order_number', 'items_table', 'added_total', 'currency',
        ],
    ];

    /** Default subject for a template ({tokens} still get strtr-substituted). */
    public static function subject(string $template): string
    {
        $key = 'mail_default.subject.'.$template;
        $subject = __($key);

        return is_string($subject) && $subject !== $key ? $subject : '{business_name}';
    }

    /** Default HTML body for a template (inline CSS, {token} placeholders). */
    public static function body(string $template): string
    {
        return match ($template) {
            MerchantMailSettings::TEMPLATE_FIRST_PAYMENT_WELCOME => self::firstPaymentWelcome(),
            MerchantMailSettings::TEMPLATE_RECURRING_PAYMENT_REMINDER => self::recurringReminder(),
            MerchantMailSettings::TEMPLATE_MANUAL_RECURRING_PAYMENT => self::manualRecurring(),
            MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED => self::chargeSucceeded(),
            MerchantMailSettings::TEMPLATE_CHARGE_FAILED => self::chargeFailed(),
            MerchantMailSettings::TEMPLATE_PLAN_CANCELLED => self::planCancelled(),
            MerchantMailSettings::TEMPLATE_LOGIN_CODE => self::loginCode(),
            MerchantMailSettings::TEMPLATE_ORDER_UPDATED => self::orderUpdated(),
            default => self::card('<p '.self::P.'>{business_name}</p>'),
        };
    }

    /** The placeholders available in a given template (UI helper text + samples). */
    public static function placeholders(string $template): array
    {
        return self::PLACEHOLDERS[$template] ?? [];
    }

    /**
     * The STARTER body a new email campaign opens with.
     *
     * Not a fallback like the templates above — a campaign has no default copy,
     * it has whatever the merchant wrote. This is the first draft they edit, and
     * it ships compliant: a call to action on the passwordless account link, and
     * an unsubscribe line in the footer, both as {tokens} the send fills in.
     */
    public static function campaignStarter(): string
    {
        return self::card(
            self::greeting()
            .self::line('mail_default.campaign.lead', self::P)
            .self::cta('mail_default.campaign.cta', '{account_login_url}')
            .'<p '.self::MUTED.'>'.__('mail_default.campaign.signature')
            .'<br><a href="{unsubscribe_url}" style="color:#6b7280;">'.__('mail_default.campaign.unsubscribe').'</a></p>'
        );
    }

    /** The subject a new campaign opens with. */
    public static function campaignStarterSubject(): string
    {
        return (string) __('mail_default.campaign.subject');
    }

    /** Reading direction for the CURRENT locale — drives the card and the layout. */
    public static function direction(): string
    {
        return in_array(self::locale(), self::RTL_LOCALES, true) ? 'rtl' : 'ltr';
    }

    public static function locale(): string
    {
        return (string) app()->getLocale();
    }

    // === Assembly (inline CSS — the allowed email exception) ===

    /**
     * The shared card shell. Direction is resolved per render rather than baked in,
     * so a shop that sends in English does not ship right-aligned mail.
     */
    private static function card(string $inner): string
    {
        $dir = self::direction();

        return '<div dir="'.$dir.'" style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1f2937;background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;">'
            .$inner
            .'</div>';
    }

    private static function line(string $key, string $style): string
    {
        return '<p '.$style.'>'.__($key).'</p>';
    }

    private static function cta(string $key, string $url): string
    {
        return '<a href="'.$url.'" '.self::CTA.'>'.__($key).'</a>';
    }

    private static function greeting(): string
    {
        return '<h1 '.self::H1.'>'.__('mail_default.greeting').'</h1>';
    }

    private static function footer(): string
    {
        return self::line('mail_default.footer', self::MUTED);
    }

    // === Default bodies ===

    private static function firstPaymentWelcome(): string
    {
        return self::card(
            self::greeting()
            .self::line('mail_default.first_payment_welcome.lead', self::P)
            .self::line('mail_default.amount', self::AMOUNT)
            // Same reason as {installment_progress}: an open-ended subscription has
            // no "N payments in the plan" to state, so the sentence disappears
            // rather than announcing a total nobody agreed to.
            .self::line('mail_default.first_payment_welcome.next', self::P)
            .self::cta('mail_default.first_payment_welcome.cta', '{portal_url}')
            .self::footer()
        );
    }

    private static function recurringReminder(): string
    {
        return self::card(
            self::greeting()
            .self::line('mail_default.recurring_payment_reminder.lead', self::P)
            .self::line('mail_default.amount', self::AMOUNT)
            .self::cta('mail_default.recurring_payment_reminder.cta', '{portal_url}')
            .self::footer()
        );
    }

    private static function manualRecurring(): string
    {
        return self::card(
            self::greeting()
            .self::line('mail_default.manual_recurring_payment.lead', self::P)
            .self::line('mail_default.manual_recurring_payment.amount', self::AMOUNT)
            .self::cta('mail_default.manual_recurring_payment.cta', '{invoice_url}')
            .self::footer()
        );
    }

    private static function chargeSucceeded(): string
    {
        return self::card(
            self::greeting()
            // {installment_progress} carries the whole "(payment X of Y)" aside, and
            // is EMPTY for an open-ended subscription — which has no Y, and was
            // being told "payment 3 of 1".
            .self::line('mail_default.charge_succeeded.lead', self::P)
            .self::line('mail_default.amount', self::AMOUNT)
            .self::cta('mail_default.charge_succeeded.cta', '{invoice_url}')
            .'<p '.self::P.'><a href="{portal_url}" style="color:#2563eb;">'.__('mail_default.charge_succeeded.secondary').'</a></p>'
            .self::footer()
        );
    }

    private static function chargeFailed(): string
    {
        return self::card(
            self::greeting()
            .self::line('mail_default.charge_failed.lead', self::P)
            .self::line('mail_default.amount', self::AMOUNT)
            .self::line('mail_default.charge_failed.reason', self::P)
            .self::cta('mail_default.charge_failed.cta', '{portal_url}')
            .self::footer()
        );
    }

    private static function planCancelled(): string
    {
        return self::card(
            self::greeting()
            .self::line('mail_default.plan_cancelled.lead', self::P)
            .self::cta('mail_default.plan_cancelled.cta', '{portal_url}')
            .self::footer()
        );
    }

    /**
     * The sign-in code. No CTA link on purpose: a one-time code that also arrives
     * as a clickable link is a phishing template, and the shopper is already
     * looking at the page that asked for it.
     */
    private static function loginCode(): string
    {
        return self::card(
            '<h1 '.self::H1.'>'.__('mail_default.login_code.heading').'</h1>'
            .self::line('mail_default.login_code.lead', self::P)
            // The digits get their own treatment: LTR and monospaced even inside
            // an RTL card, because a bidi-reordered code is unreadable.
            .'<p '.self::CODE.'>{code}</p>'
            .self::line('mail_default.login_code.valid', self::P)
            .self::line('mail_default.login_code.footer', self::MUTED)
        );
    }

    /**
     * "Your order was updated."
     *
     * {items_table} is a PRE-RENDERED scalar, not a loop — strtr substitutes
     * scalars and nothing else, which is the wall that keeps merchant HTML away
     * from a compiler. OrderUpdatedNotifier builds the rows.
     */
    private static function orderUpdated(): string
    {
        return self::card(
            '<h1 '.self::H1.'>'.__('mail_default.order_updated.heading').'</h1>'
            .self::line('mail_default.order_updated.lead', self::P)
            .'{items_table}'
            .self::line('mail_default.order_updated.total', self::AMOUNT)
            .self::line('mail_default.order_updated.closing', self::P)
            .self::line('mail_default.order_updated.footer', self::MUTED)
        );
    }
}
