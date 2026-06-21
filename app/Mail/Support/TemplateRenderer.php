<?php

namespace App\Mail\Support;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
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
     * Substitute {token} placeholders using strtr() ONLY.
     *
     * @param array<string, scalar|null> $vars  token => value (without braces)
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

        return [
            'customer_name' => self::nonEmpty($plan->customer_name, self::FALLBACK_CUSTOMER),
            'customer_email' => (string) ($plan->customer_email ?? ''),
            'business_name' => $businessName,
            'product_title' => self::nonEmpty(self::productTitleFor($plan), self::FALLBACK_PRODUCT),
            'amount' => self::money($amount),
            'currency' => $currency,
            'plan_id' => (string) $plan->getKey(),
            'installment_count' => self::installmentCountFor($plan),
            'installment_sequence' => $payment !== null ? (string) ($payment->sequence ?? '') : '',
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
        return ($plan->meta['product_title'] ?? null) ?: null;
    }

    /** Total installment count for the plan (from meta, else derived). */
    private static function installmentCountFor(InstallmentPlan $plan): string
    {
        $count = $plan->meta['installment_count'] ?? null;

        if ($count !== null) {
            return (string) (int) $count;
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
