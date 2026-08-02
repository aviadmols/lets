<?php

namespace App\Domain\Installments;

use App\Models\InstallmentPlan;
use App\Models\ProductSubscriptionPlan;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Support\Facades\Log;

/**
 * Captures CHECKOUT pricing facts onto a freshly-activated recurring plan, called
 * by PlanActivationService inside the activation transaction (which only runs
 * once per plan — the awaiting_first_payment guard is the idempotency wall):
 *
 *   1. the coupon/discount codes the shopper used (Shopify `discount_codes[]`,
 *      WooCommerce `coupon_lines[]` — the finalizer copies them onto the payload)
 *      → META_CHECKOUT_DISCOUNT, for display + order tagging ONLY, never money;
 *   2. keep_first_payment: the amount ACTUALLY PAID for this plan's line at
 *      checkout becomes the steady-state cycle amount (installment_amount) — the
 *      whole point of the mode: a product bought at a discount keeps that price.
 *
 * Attribution rule for (2): the paid amount comes from the ONE order line that
 * matches the plan's variant (falling back to product, falling back to a
 * single-line order's total). Ambiguity — no match, several matches, zero/missing
 * money — SKIPS the write-back and keeps the template-derived amount: charging a
 * guessed amount on a saved token is worse than charging the advertised price.
 */
final class CheckoutPricingCapture
{
    // === CONSTANTS ===
    /** Skip-reasons written to the log + Timeline detail. */
    public const SKIP_AMBIGUOUS_LINES = 'ambiguous_lines';
    public const SKIP_NO_AMOUNT = 'no_amount';

    /** Run both captures. Recurring plans only — installments keep their quote. */
    public function apply(InstallmentPlan $plan, array $orderPayload): void
    {
        if (! $plan->isRecurring()) {
            return;
        }

        $this->captureCheckoutDiscount($plan, $orderPayload);
        $this->applyKeepFirstPayment($plan, $orderPayload);
    }

    /**
     * Store the checkout coupon (codes + total discount) in plan meta when the
     * order carried one. Shape: {codes: string[], amount: float, type: ?string}.
     */
    public function captureCheckoutDiscount(InstallmentPlan $plan, array $orderPayload): void
    {
        $codes = [];
        $amount = 0.0;
        $type = null;

        // Shopify orders/paid: discount_codes[] = [{code, amount, type}].
        foreach ((array) data_get($orderPayload, 'discount_codes', []) as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $codes[] = $code;
            $amount += (float) ($row['amount'] ?? 0);
            $type ??= ($row['type'] ?? null) !== null ? (string) $row['type'] : null;
        }

        // WooCommerce REST order: coupon_lines[] = [{code, discount}].
        foreach ((array) data_get($orderPayload, 'coupon_lines', []) as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $codes[] = $code;
            $amount += (float) ($row['discount'] ?? 0);
        }

        if ($codes === []) {
            return;
        }

        // Prefer the order's own total when it carries one (covers automatic
        // discounts Shopify reports only in total_discounts).
        $orderTotalDiscount = (float) (data_get($orderPayload, 'total_discounts') ?? 0);
        if ($orderTotalDiscount > 0) {
            $amount = $orderTotalDiscount;
        }

        $meta = (array) ($plan->meta ?? []);
        $meta[InstallmentPlan::META_CHECKOUT_DISCOUNT] = [
            'codes' => array_values(array_unique($codes)),
            'amount' => round($amount, 2),
            'type' => $type,
        ];
        $plan->forceFill(['meta' => $meta])->save();

        Timeline::record(
            kind: Timeline::KIND_CHECKOUT_DISCOUNT_CAPTURED,
            details: [
                'coupon_codes' => array_values(array_unique($codes)),
                'amount' => round($amount, 2),
            ],
            planId: $plan->getKey(),
            shopId: (int) $plan->shop_id,
        );
    }

    /**
     * keep_first_payment: write the actually-paid line amount back as the plan's
     * steady-state cycle amount. regular_amount stays untouched — it is the
     * undiscounted price the discount-tag predicate compares against.
     */
    public function applyKeepFirstPayment(InstallmentPlan $plan, array $orderPayload): void
    {
        if ($plan->pricing_mode !== ProductSubscriptionPlan::PRICING_KEEP_FIRST) {
            return;
        }

        $paid = $this->paidAmountForPlan($plan, $orderPayload);
        if ($paid === null || $paid <= 0) {
            Log::info('installments.keep_first.attribution_skipped', [
                'shop_id' => $plan->shop_id,
                'plan_id' => $plan->getKey(),
                'reason' => $paid === null ? self::SKIP_AMBIGUOUS_LINES : self::SKIP_NO_AMOUNT,
            ]);

            return;
        }

        $paid = round($paid, 2);
        if ($paid === round((float) $plan->installment_amount, 2)) {
            return; // paid exactly the advertised price — nothing to keep
        }

        $meta = (array) ($plan->meta ?? []);
        $meta[RecurringPlanService::META_RECURRING_AMOUNT] = $paid;
        $plan->forceFill([
            'installment_amount' => $paid,
            'meta' => $meta,
        ])->save();
    }

    /**
     * The amount paid at checkout FOR THIS PLAN's item, or null when attribution
     * is ambiguous. Matches the plan's variant id against the order lines —
     * Shopify `variant_id`, WooCommerce `variation_id`/`product_id` — and falls
     * back to the order total only for a single-line order.
     */
    private function paidAmountForPlan(InstallmentPlan $plan, array $orderPayload): ?float
    {
        $lines = array_values(array_filter(
            (array) data_get($orderPayload, 'line_items', []),
            'is_array',
        ));

        $matches = array_values(array_filter($lines, fn (array $line): bool => $this->lineMatchesPlan($line, $plan)));

        if (count($matches) === 1) {
            return $this->linePaidAmount($matches[0]);
        }

        // No (or several) matching lines: a SINGLE-line order is still unambiguous.
        if ($matches === [] && count($lines) === 1) {
            return $this->linePaidAmount($lines[0]);
        }

        // Last resort: a one-item order whose payload has no line detail at all —
        // trust total_price (the Shopify hosted-page flow for one plan).
        if ($lines === []) {
            $total = data_get($orderPayload, 'total_price');

            return is_numeric($total) && (float) $total > 0 ? (float) $total : null;
        }

        return null;
    }

    /** Does this order line sell the plan's variant (or product, when variant-less)? */
    private function lineMatchesPlan(array $line, InstallmentPlan $plan): bool
    {
        $planVariant = (string) ($plan->external_variant_id ?: $plan->shopify_variant_id ?: '');
        $planProduct = (string) ($plan->external_product_id ?: $plan->shopify_product_id ?: '');

        $lineVariant = (string) ($line['variant_id'] ?? $line['variation_id'] ?? '');
        $lineProduct = (string) ($line['product_id'] ?? '');

        if ($planVariant !== '' && $lineVariant !== '') {
            return $planVariant === $lineVariant;
        }

        return $planProduct !== '' && $lineProduct !== '' && $planProduct === $lineProduct;
    }

    /**
     * The DISCOUNTED money a line actually collected.
     * Shopify: price×qty − discount_allocations (fallback total_discount).
     * WooCommerce: total (+ total_tax — catalog prices include VAT, WC splits it).
     */
    private function linePaidAmount(array $line): ?float
    {
        // WooCommerce REST shape: `total` is the post-coupon line total ex tax.
        if (isset($line['total']) && is_numeric($line['total'])) {
            return round((float) $line['total'] + (float) ($line['total_tax'] ?? 0), 2);
        }

        // Shopify shape: price × quantity − allocated discounts.
        if (isset($line['price']) && is_numeric($line['price'])) {
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $gross = (float) $line['price'] * $qty;

            $allocated = 0.0;
            foreach ((array) ($line['discount_allocations'] ?? []) as $allocation) {
                $allocated += (float) (data_get($allocation, 'amount') ?? 0);
            }
            if ($allocated <= 0) {
                $allocated = (float) ($line['total_discount'] ?? 0);
            }

            return round($gross - $allocated, 2);
        }

        return null;
    }
}
