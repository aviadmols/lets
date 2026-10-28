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
 *      whole point of the mode: a product bought at a discount keeps that price;
 *   3. a WooCommerce coupon whose SUBSCRIPTION SCOPE is "all cycles" (the WP
 *      plugin stamps the scope the coupon was sold under on the order's coupon
 *      line — class-lets-coupons.php) does the same write-back for that one
 *      plan, whatever the template's pricing mode. "first cycle" (the default,
 *      and every Shopify coupon) changes nothing: the coupon discounted the
 *      checkout, renewals bill the plan's regular price.
 *
 * Attribution rule for (2) and (3): the paid amount comes from the ONE order line
 * that matches the plan's variant (falling back to product, falling back to a
 * single-line order's total). Ambiguity — no match, several matches, zero/missing
 * money — SKIPS the write-back and keeps the template-derived amount: charging a
 * guessed amount on a saved token is worse than charging the advertised price.
 * (3) has one more rung: a PERCENT coupon's math needs no attribution at all, so
 * an ambiguous order still honors it from the plan's own amount.
 */
final class CheckoutPricingCapture
{
    // === CONSTANTS ===
    /** Skip-reasons written to the log + Timeline detail. */
    public const SKIP_AMBIGUOUS_LINES = 'ambiguous_lines';

    public const SKIP_NO_AMOUNT = 'no_amount';

    /** Coupon subscription scopes. Mirror the WP plugin's LETS_COUPON_SCOPE_* constants. */
    public const COUPON_SCOPE_FIRST_CYCLE = 'first_cycle';

    public const COUPON_SCOPE_ALL_CYCLES = 'all_cycles';

    /** The WC coupon-line meta keys the plugin stamps (REST-visible, so no underscore). */
    public const LINE_META_SCOPE = 'lets_subscription_scope';

    public const LINE_META_TYPE = 'lets_coupon_type';

    public const LINE_META_AMOUNT = 'lets_coupon_amount';

    /** WooCommerce's percent coupon type — the one whose math needs no line attribution. */
    public const WOO_TYPE_PERCENT = 'percent';

    /** Run all captures. Recurring plans only — installments keep their quote. */
    public function apply(InstallmentPlan $plan, array $orderPayload): void
    {
        if (! $plan->isRecurring()) {
            return;
        }

        $this->captureCheckoutDiscount($plan, $orderPayload);
        $this->applyKeepFirstPayment($plan, $orderPayload);
        $this->applyAllCyclesCoupon($plan, $orderPayload);
    }

    /**
     * Store the checkout coupon (codes + total discount) in plan meta when the
     * order carried one. Shape: {codes: string[], amount: float, type: ?string,
     * scope: ?string} — scope is the WooCommerce subscription scope when the
     * plugin stamped one (all_cycles wins over first_cycle across several coupons).
     */
    public function captureCheckoutDiscount(InstallmentPlan $plan, array $orderPayload): void
    {
        $codes = [];
        $amount = 0.0;
        $type = null;
        $scope = null;

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

        // WooCommerce REST order: coupon_lines[] = [{code, discount, meta_data[]}].
        foreach ((array) data_get($orderPayload, 'coupon_lines', []) as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $codes[] = $code;
            $amount += (float) ($row['discount'] ?? 0);

            $lineScope = $this->couponLineScope($row);
            if ($lineScope !== null && $scope !== self::COUPON_SCOPE_ALL_CYCLES) {
                $scope = $lineScope;
            }
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
            'scope' => $scope,
        ];
        $plan->forceFill(['meta' => $meta])->save();

        Timeline::record(
            kind: Timeline::KIND_CHECKOUT_DISCOUNT_CAPTURED,
            details: array_filter([
                'coupon_codes' => array_values(array_unique($codes)),
                'amount' => round($amount, 2),
                'scope' => $scope,
            ], static fn ($value): bool => $value !== null),
            planId: $plan->getKey(),
            shopId: (int) $plan->shop_id,
        );
    }

    /**
     * all_cycles coupon: the discounted amount paid at checkout becomes the plan's
     * steady-state cycle amount — the same write-back as keep_first_payment, for
     * this plan only, whatever the template's mode. Skipped when keep_first already
     * did it (same number twice is pointless). regular_amount gains the undiscounted
     * price when the plan had none, so the discount tag can tell the two apart;
     * when the plan has an intro window, the step-up price is discounted by the
     * same ratio — "all cycles" includes the ones after the window.
     */
    public function applyAllCyclesCoupon(InstallmentPlan $plan, array $orderPayload): void
    {
        if ($plan->pricing_mode === ProductSubscriptionPlan::PRICING_KEEP_FIRST) {
            return;
        }

        $lines = $this->allCyclesCouponLines($orderPayload);
        if ($lines === []) {
            return;
        }

        $current = round((float) $plan->installment_amount, 2);
        $paid = $this->paidAmountForPlan($plan, $orderPayload);
        $reason = null;

        if ($paid === null || $paid <= 0) {
            // Attribution failed — a percent coupon can still be honored from the
            // plan's own amount; anything else would be a guess.
            $percent = $this->percentFromLines($lines);
            if ($percent !== null) {
                $paid = $current * (1 - $percent / 100);
            } else {
                $reason = $paid === null ? self::SKIP_AMBIGUOUS_LINES : self::SKIP_NO_AMOUNT;
            }
        }

        if ($reason !== null || $paid === null) {
            Log::info('installments.coupon_all_cycles.attribution_skipped', [
                'shop_id' => $plan->shop_id,
                'plan_id' => $plan->getKey(),
                'reason' => $reason ?? self::SKIP_NO_AMOUNT,
            ]);

            return;
        }

        $paid = round(max($paid, 0), 2);
        if ($paid === $current || $paid <= 0) {
            return; // paid the advertised price (or a free first cycle) — nothing to keep
        }

        $fill = ['installment_amount' => $paid];
        $regular = $plan->regular_amount !== null ? round((float) $plan->regular_amount, 2) : null;
        if ($regular === null || $regular <= 0) {
            $fill['regular_amount'] = $current;
        } elseif ((int) ($plan->discount_cycles ?? 0) > 0 && $current > 0) {
            $fill['regular_amount'] = round($regular * ($paid / $current), 2);
        }

        $meta = (array) ($plan->meta ?? []);
        $meta[RecurringPlanService::META_RECURRING_AMOUNT] = $paid;
        $fill['meta'] = $meta;

        $plan->forceFill($fill)->save();
    }

    /** The coupon lines the plugin stamped as all_cycles, or [] when none. */
    private function allCyclesCouponLines(array $orderPayload): array
    {
        return array_values(array_filter(
            (array) data_get($orderPayload, 'coupon_lines', []),
            fn ($row): bool => is_array($row) && $this->couponLineScope($row) === self::COUPON_SCOPE_ALL_CYCLES,
        ));
    }

    /**
     * The combined percentage of the all_cycles coupons, or null when any of them
     * is not a percent coupon (a fixed amount cannot be re-derived without the line).
     */
    private function percentFromLines(array $lines): ?float
    {
        $remaining = 1.0;
        foreach ($lines as $row) {
            if ($this->couponLineMeta($row, self::LINE_META_TYPE) !== self::WOO_TYPE_PERCENT) {
                return null;
            }
            $value = (float) ($this->couponLineMeta($row, self::LINE_META_AMOUNT) ?? 0);
            if ($value <= 0 || $value > 100) {
                return null;
            }
            // WooCommerce applies stacked percent coupons sequentially.
            $remaining *= 1 - $value / 100;
        }

        return round((1 - $remaining) * 100, 4);
    }

    /** The scope stamped on a WC coupon line, or null when the plugin sent none. */
    private function couponLineScope(array $row): ?string
    {
        $scope = $this->couponLineMeta($row, self::LINE_META_SCOPE);

        return in_array($scope, [self::COUPON_SCOPE_FIRST_CYCLE, self::COUPON_SCOPE_ALL_CYCLES], true) ? $scope : null;
    }

    /** One meta value off a WC REST coupon line (meta_data[] = [{key, value}]). */
    private function couponLineMeta(array $row, string $key): ?string
    {
        foreach ((array) ($row['meta_data'] ?? []) as $meta) {
            if (is_array($meta) && (string) ($meta['key'] ?? '') === $key) {
                $value = $meta['value'] ?? null;

                return is_scalar($value) ? trim((string) $value) : null;
            }
        }

        return null;
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
