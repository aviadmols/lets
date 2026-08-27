<?php

namespace App\Mail\Support;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use Illuminate\Support\Carbon;

/**
 * Substitutes {placeholder} tokens in merchant-edited email subjects/bodies.
 *
 * LOCKED SECURITY LAW (CLAUDE.md, reference engine's Mail/TemplateRenderer):
 * merchant input is substituted with strtr() ONLY — NEVER Blade::render(),
 * Blade::compileString(), eval(), or any template compiler. A merchant body that
 * contains `{{ 7*7 }}`, `@php ... @endphp`, or `{!! ... !!}` is treated as inert
 * literal text: strtr replaces ONLY the exact {token} keys we hand it and leaves
 * everything else byte-for-byte. This is RCE prevention, not a nicety.
 *
 * The renderer also BUILDS the variable bag from an InstallmentPlan /
 * InstallmentPayment (+ business name + portal/invoice URLs) so each mailable
 * passes a model, not a hand-assembled array. Every value is cast to string and
 * passed through strtr as a VALUE (never as part of the search key), so a value
 * that itself contains a `{token}` is not re-expanded (single-pass substitution).
 *
 * Ported + multi-tenant-refactored from the reference engine's TemplateRenderer.
 */
final class TemplateRenderer
{
    // === CONSTANTS ===
    /** Token delimiters — placeholders are written {snake_case}. */
    private const OPEN = '{';

    private const CLOSE = '}';

    /** Default product label when a plan has no product title. */
    private const FALLBACK_PRODUCT = 'your order';

    /** Default customer salutation when no name is on file. */
    private const FALLBACK_CUSTOMER = 'there';

    /**
     * The "(payment X of Y)" aside, as a single substituted fragment.
     *
     * It cannot be a conditional in the template: the default bodies are token
     * STRINGS, substituted with strtr long after any Blade ran, so a `@if` there
     * sees the literal `{installment_sequence}` and is always true. The decision
     * therefore belongs to the var bag — and an open-ended subscription gets an
     * empty string, not a fabricated total.
     *
     * The format string is a TRANSLATION, resolved from the locale the mailable
     * bound before rendering: a shop that sends in English must not have a Hebrew
     * clause spliced into the middle of its sentence.
     */
    private const PROGRESS_KEY = 'mail_default.progress';

    /** The welcome email's "N payments in the plan" sentence — same rule. */
    private const TOTAL_NOTE_KEY = 'mail_default.total_note';

    /**
     * Substitute {token} placeholders using strtr() ONLY.
     *
     * @param  array<string, scalar|null>  $vars  token => value (without braces)
     */
    public static function render(string $template, array $vars): string
    {
        if ($template === '') {
            return '';
        }

        // Build the strtr replacement map: '{token}' => (string) value. strtr does
        // a SINGLE non-overlapping pass keyed on the longest match — it never
        // interprets the replacement values, so no value can re-trigger another
        // substitution and merchant HTML is never compiled.
        $map = [];
        foreach ($vars as $key => $value) {
            $map[self::OPEN.$key.self::CLOSE] = self::stringify($value);
        }

        return strtr($template, $map);
    }

    /**
     * The full variable bag for a plan-based mail. Mailables call this, then add
     * any template-specific extras (failure_reason, cancellation_reason, …) before
     * rendering.
     *
     * @return array<string, scalar|null>
     */
    public static function planVars(
        InstallmentPlan $plan,
        string $businessName,
        ?InstallmentPayment $payment = null,
        ?string $portalUrl = null,
        ?string $invoiceUrl = null,
    ): array {
        $currency = (string) ($plan->currency ?: config('payplus.currency', 'ILS'));
        $amount = $payment !== null
            ? (float) $payment->amount
            : (float) ($plan->installment_amount ?: 0);

        $count = self::installmentCountFor($plan);
        $sequence = $payment !== null ? (string) ($payment->sequence ?? '') : '';

        return [
            'customer_name' => self::nonEmpty($plan->customer_name, self::FALLBACK_CUSTOMER),
            'customer_email' => (string) ($plan->customer_email ?? ''),
            'business_name' => $businessName,
            'product_title' => self::nonEmpty(self::productTitleFor($plan), self::FALLBACK_PRODUCT),
            'amount' => self::money($amount),
            'currency' => $currency,
            'plan_id' => (string) $plan->getKey(),
            'installment_count' => $count,
            'installment_sequence' => $sequence,
            'installment_progress' => self::progress($sequence, $count),
            'installment_total_note' => $count !== '' ? sprintf((string) __(self::TOTAL_NOTE_KEY), $count) : '',
            'next_charge_date' => self::date($plan->next_charge_at),
            'portal_url' => (string) ($portalUrl ?? ''),
            'invoice_url' => (string) ($invoiceUrl ?? ''),
        ];
    }

    /** Coerce a value to a safe display string for substitution. */
    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private static function nonEmpty(?string $value, string $fallback): string
    {
        return ($value === null || trim($value) === '') ? $fallback : $value;
    }

    private static function money(float $amount): string
    {
        return number_format(round($amount, 2), 2);
    }

    private static function date(mixed $when): string
    {
        if ($when === null) {
            return '';
        }

        return $when instanceof Carbon
            ? $when->format('d/m/Y')
            : Carbon::parse($when)->format('d/m/Y');
    }

    /** Product label for the plan: meta.product_title, else generic fallback. */
    private static function productTitleFor(InstallmentPlan $plan): ?string
    {
        // Delegated: reading meta['product_title'] alone missed the checkout rail's
        // meta['item_title'], so customers whose plan knew the product were told
        // "your order".
        return $plan->productTitle();
    }

    /**
     * "(payment X of Y)", or nothing at all when either half is unknown.
     *
     * Public because the admin's Timeline preview recomputes it: it recovers the
     * sequence from the event AFTER planVars() has run, and a stale progress line
     * built from the empty sequence would silently drop the aside.
     */
    public static function progress(string $sequence, string $count): string
    {
        return ($sequence !== '' && $count !== '')
            ? sprintf((string) __(self::PROGRESS_KEY), $sequence, $count)
            : '';
    }

    /**
     * Total installment count for the plan (from meta, else derived).
     *
     * EMPTY for an open-ended recurring subscription, because there is no total to
     * count towards. Deriving one from total/per gave a plan billing ₪1 a month a
     * "count" of 1, and customers were emailed "payment 3 of 1" — a sentence that
     * is not merely odd, it tells them their subscription ended two cycles ago.
     */
    private static function installmentCountFor(InstallmentPlan $plan): string
    {
        $count = $plan->meta['installment_count'] ?? null;

        if ($count !== null) {
            return (string) (int) $count;
        }

        if ($plan->plan_kind === PlanKind::RECURRING) {
            return '';
        }

        // Derived: total / per-installment amount, when both are known.
        $per = (float) ($plan->installment_amount ?: 0);
        $total = (float) ($plan->total_amount ?: 0);

        if ($per > 0 && $total > 0) {
            return (string) (int) ceil($total / $per);
        }

        // Fall back to the number of payment slots already recorded.
        return (string) max(1, $plan->payments()->count());
    }

    /** Convenience: the sequence position for a succeeded-charge confirmation. */
    public static function succeededSequence(InstallmentPlan $plan): int
    {
        return (int) $plan->payments()
            ->where('status', PaymentStatus::SUCCEEDED->value)
            ->count();
    }
}
