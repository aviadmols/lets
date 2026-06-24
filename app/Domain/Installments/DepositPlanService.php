<?php

namespace App\Domain\Installments;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\Orders\PlatformInvoiceServiceFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a DEPOSIT + installments plan from a verified storefront request, then
 * the UNPAID Shopify deposit invoice the customer pays to activate it (W9 Part C).
 *
 * Ownership boundary: this builds the Shopify SHAPE + the plan ROW; it never
 * charges. The plan is born `awaiting_first_payment` with NO succeeded payment and
 * a null next_charge_at — the recurring engine (DispatchDuePlansCommand /
 * ChargeOrchestrator) only takes over AFTER orders/paid activates the deposit
 * (PlanActivationService sets next_charge_at to the first scheduled slice).
 *
 * Money law: the amounts come from the already-clamped, server-computed
 * InstallmentQuote — the storefront never sends an amount it controls. Tenant law:
 * $shop is the App-Proxy-verified shop ONLY; BelongsToShop auto-stamps shop_id, and
 * we forceFill the guarded shop_id/status to the exact tenant + initial state.
 */
final class DepositPlanService
{
    // === CONSTANTS ===
    /** meta keys that link the plan to its deposit draft + invoice. */
    public const META_DRAFT_GID = 'deposit_draft_gid';
    public const META_DRAFT_ID = 'deposit_draft_id';
    public const META_INVOICE_URL = 'deposit_invoice_url';
    public const META_DEPOSIT_AMOUNT = 'deposit_amount';
    public const META_QUOTE = 'deposit_quote';

    /**
     * Create the plan + its unpaid deposit invoice; return the plan and the hosted
     * invoice URL the storefront redirects the parent window to.
     *
     * @param  array{
     *     product_gid: string, variant_gid: string, item_title: string,
     *     customer_email?: ?string, customer_name?: ?string, customer_phone?: ?string,
     *     shopify_customer_id?: ?string
     * }  $context
     * @return array{plan: InstallmentPlan, invoice_url: string}
     */
    public function create(Shop $shop, InstallmentQuote $quote, array $context): array
    {
        // Resolve the platform's deposit-invoice service up front (Shopify draft order /
        // WooCommerce PayPlus page) — fail fast before creating a plan we can't invoice.
        $invoiceService = PlatformInvoiceServiceFactory::for($shop);
        if ($invoiceService === null) {
            throw new \RuntimeException("No deposit-invoice service for platform [{$shop->platform}].");
        }

        // Build the plan row inside a txn; the deposit invoice (an external call) is
        // made AFTER commit so a Shopify hiccup never leaves a phantom plan with no
        // way to pay — and a created-but-uninvoiced plan is reconcilable by its
        // awaiting_first_payment status + missing invoice meta.
        $plan = DB::transaction(function () use ($shop, $quote, $context): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill([
                'customer_id' => null,
                'shopify_customer_id' => $context['shopify_customer_id'] ?? null,
                'shopify_variant_id' => ProductPriceResolver::numericId((string) $context['variant_gid']) ?: null,
                'shopify_product_id' => ProductPriceResolver::numericId((string) $context['product_gid']) ?: null,
                'plan_kind' => PlanKind::INSTALLMENTS->value,
                'charge_context' => 'deposit',
                'total_amount' => $quote->totalAmount,
                'total_charged' => 0,
                'installment_amount' => $quote->installmentAmount,
                'currency' => $quote->currency,
                'billing_frequency' => $quote->frequency->value,
                'interval_count' => 1,
                // next_charge_at stays NULL until the deposit is paid (orders/paid
                // → PlanActivationService sets it to the first scheduled slice).
                'next_charge_at' => null,
                'requires_manual_payment' => false,
                'public_id' => (string) Str::ulid(),
                'customer_email' => $context['customer_email'] ?? null,
                'customer_name' => $context['customer_name'] ?? null,
                'customer_phone' => $context['customer_phone'] ?? null,
                'meta' => [
                    self::META_DEPOSIT_AMOUNT => $quote->depositAmount,
                    self::META_QUOTE => $quote->toArray(),
                ],
            ]);

            // shop_id + status are guarded (tenancy + the state machine own them).
            // Stamp the verified tenant + the initial status explicitly via forceFill.
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
            ])->save();

            return $plan;
        });

        // Create the UNPAID deposit invoice (Shopify draft order / PayPlus hosted page).
        $invoice = $invoiceService->createDepositInvoice($plan, [
            'title' => (string) $context['item_title'],
            'deposit_amount' => $quote->depositAmount,
            'quantity' => 1,
            'variant_gid' => (string) $context['variant_gid'],
        ]);

        // Persist the invoice linkage so the paid-order webhook can find the plan by
        // its external ref / note attribute and activate it.
        $meta = (array) ($plan->meta ?? []);
        $meta[self::META_DRAFT_GID] = $invoice['external_gid'];
        $meta[self::META_DRAFT_ID] = $invoice['external_ref'];
        $meta[self::META_INVOICE_URL] = $invoice['invoice_url'];
        $plan->meta = $meta;
        // The deposit invoice's order/draft becomes the parent order on payment.
        $plan->shopify_order_id = $invoice['external_ref'] !== '' ? $invoice['external_ref'] : $plan->shopify_order_id;
        $plan->save();

        Timeline::record(
            kind: 'deposit_plan_created',
            details: [
                'plan_public_id' => $plan->public_id,
                'deposit_amount' => $quote->depositAmount,
                'total_amount' => $quote->totalAmount,
                'installments' => $quote->installments,
                'external_ref' => $invoice['external_ref'],
            ],
            planId: $plan->getKey(),
            shopId: (int) $shop->getKey(),
        );

        return ['plan' => $plan, 'invoice_url' => $invoice['invoice_url']];
    }

    /** Resolve a BillingFrequency from a storefront-supplied string (fail to default). */
    public static function frequencyFrom(?string $value): BillingFrequency
    {
        $freq = BillingFrequency::tryFrom((string) $value);

        return ($freq !== null && in_array($freq, InstallmentQuote::ALLOWED_FREQUENCIES, true))
            ? $freq
            : InstallmentQuote::DEFAULT_FREQUENCY;
    }
}
