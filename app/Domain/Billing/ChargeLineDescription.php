<?php

namespace App\Domain\Billing;

use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Support\Tenant;

/**
 * The SENTENCE PayPlus prints on the document it issues for a charge.
 *
 * WHY THIS EXISTS, in one paragraph of scar tissue. A PayPlus terminal can be
 * configured to auto-issue a tax document per transaction. It takes that
 * document's line from the `items` array we send with the charge — and when we
 * send none, it falls back to `more_info`, which in this app is the deterministic
 * idempotency key. That is how a real customer received a חשבונית מס קבלה whose
 * product name read "payplus_installment_plan_41_payment_2". The line is not
 * cosmetic: it is on paperwork a customer reads and an accountant files.
 *
 * So every charge gets a human line, and for RECURRING cycles the merchant writes
 * it themselves — they are the ones who know whether their customers should read
 * "מנוי חודשי", "דמי חבר" or the product's own name. The template is stored on
 * `merchant_billing_settings` and substituted here with strtr(), NEVER through a
 * template engine: merchant-authored text is data in this app, always substituted
 * and never executed.
 *
 * Deposits and instalments carry a TRANSLATED template rather than a second
 * setting — same renderer, same placeholders, merchant simply does not own the
 * sentence. One editable line is a feature a merchant holds in their head where
 * three are a form they abandon.
 *
 * ONE renderer, called twice: the charge path and the settings screen's live
 * preview both go through render(), so a preview can never promise a sentence the
 * charge would not send.
 */
final class ChargeLineDescription
{
    // === CONSTANTS ===
    /**
     * The placeholders a merchant may type, and what each resolves to.
     *
     * Documented HERE and rendered into the settings screen's helper text from
     * this same table, so the form can never advertise a placeholder the
     * substitution does not know.
     *
     * @var array<string, string> placeholder => the translation key describing it
     */
    public const PLACEHOLDERS = [
        '{plan}' => 'billing.settings.recurring.placeholder_plan',
        '{cycle}' => 'billing.settings.recurring.placeholder_cycle',
        '{frequency}' => 'billing.settings.recurring.placeholder_frequency',
        '{customer}' => 'billing.settings.recurring.placeholder_customer',
        '{id}' => 'billing.settings.recurring.placeholder_id',
    ];

    /**
     * The longest line we will send.
     *
     * PayPlus prints this on a document; a sentence that overflows the line is
     * worse than a short one, and the settings column is sized to match.
     */
    public const MAX_LENGTH = MerchantBillingSettings::MAX_CHARGE_DESCRIPTION;

    /**
     * What a placeholder becomes when the plan cannot answer it.
     *
     * An em-dash, not an empty string: "מנוי חודשי — " with nothing after it reads
     * as a bug on a customer's invoice, and a missing product name is the case this
     * class must degrade gracefully around (an imported member's product may not be
     * in the catalog at all).
     */
    public const UNKNOWN = '—';

    /**
     * The line for one charge.
     *
     * @param  int  $cycle  the charge's sequence within the plan (1-based)
     */
    public function for(InstallmentPlan $plan, PaymentType $type, int $cycle = 1): string
    {
        $template = $type === PaymentType::RECURRING
            ? $this->template($plan)
            // Not merchant-owned, but the SAME placeholder vocabulary, so the one
            // renderer below serves both and neither can drift.
            : __('billing.charge_line.'.$type->value);

        return $this->render($template, $plan, $cycle);
    }

    /**
     * Substitute the placeholders and return ONE printable line.
     *
     * Public because the settings screen renders its live preview through this
     * exact method, with the sentence the merchant is currently typing — the only
     * way a preview and a charge cannot disagree.
     *
     * strtr(), never a template engine, and one pass: strtr replaces each
     * occurrence of each key exactly once and never re-scans what it wrote, so a
     * product title that happens to contain "{plan}" cannot expand a second time.
     *
     * @param  int  $cycle  the charge's sequence within the plan (1-based)
     */
    public function render(string $template, InstallmentPlan $plan, int $cycle = 1): string
    {
        $line = strtr($template, [
            '{plan}' => $this->planLabel($plan),
            '{cycle}' => (string) max(1, $cycle),
            '{frequency}' => $this->frequencyLabel($plan),
            '{customer}' => $this->clean($plan->customerLabel()),
            '{id}' => (string) ($plan->public_id ?? ''),
        ]);

        // A template of nothing but unresolvable tokens can still collapse to an
        // empty string. Never send one: PayPlus would fall back to more_info and
        // print the idempotency key, which is the whole disaster this class exists
        // to prevent.
        return $this->clamp($line)
            ?: $this->clamp($this->fallback($plan))
            ?: self::UNKNOWN;
    }

    /**
     * The shop's stored template, or the translated default.
     *
     * Read off the PLAN's own shop rather than the bound tenant — the same pattern
     * the orchestrator uses for retry policy — so a charge can never be labelled
     * with another shop's sentence. A shop with no settings row gets the default.
     */
    private function template(InstallmentPlan $plan): string
    {
        $shop = $plan->shop;

        // Read INSIDE the plan's own tenant context. MerchantBillingSettings is
        // BelongsToShop-scoped, so a hand-written `where('shop_id', …)` for a
        // shop other than the bound one resolves to NO row — which would silently
        // fall back to the default wording instead of using the merchant's. Safe,
        // but wrong, and the claim above deserves to be true rather than lucky.
        $settings = $shop === null
            ? null
            : Tenant::run($shop, static fn (): ?MerchantBillingSettings => MerchantBillingSettings::query()->first());

        return $settings?->recurringChargeDescription()
            ?? __('billing.settings.recurring.description_default');
    }

    /**
     * What the customer bought, as they would recognise it.
     *
     * The plan's captured title first, then the synced catalog, then the plan's own
     * public id — a poor product name but an excellent reference, and still
     * infinitely better than an idempotency key.
     */
    private function planLabel(InstallmentPlan $plan): string
    {
        $title = trim((string) ($plan->itemTitle() ?? $plan->productTitle() ?? ''));

        return $title !== '' ? $this->clean($title) : $this->fallback($plan);
    }

    private function frequencyLabel(InstallmentPlan $plan): string
    {
        $unit = $plan->billing_frequency;

        if ($unit === null) {
            return self::UNKNOWN;
        }

        $count = max(1, (int) $plan->interval_count);

        return $count > 1
            ? __('subscriptions.cadence.every_n', ['n' => $count, 'unit' => __('subscriptions.cadence.plural.'.$unit->value)])
            : __('billing.settings.frequency.'.$unit->value);
    }

    private function fallback(InstallmentPlan $plan): string
    {
        $id = trim((string) ($plan->public_id ?? ''));

        return $id !== '' ? __('billing.charge_line.fallback', ['reference' => $id]) : self::UNKNOWN;
    }

    /**
     * One line, printable. Newlines and control characters are stripped rather than
     * escaped: this string is not HTML and not a shell argument — it is a line on a
     * printed document, and a line break in it is a broken document.
     */
    private function clean(string $value): string
    {
        $flat = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $flat) ?? $flat);
    }

    private function clamp(string $value): string
    {
        $clean = $this->clean($value);

        return mb_strlen($clean) > self::MAX_LENGTH
            ? mb_substr($clean, 0, self::MAX_LENGTH)
            : $clean;
    }
}
