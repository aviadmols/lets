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
 * Creates an open-ended RECURRING subscription plan from a verified storefront request
 * (W11 P3) — the WooCommerce analogue of DepositPlanService, but for plan_kind=recurring:
 * NO deposit, NO finite slices. The customer pays the FIRST cycle on a PayPlus hosted
 * page; on payment the SAME deposit callback (PlanActivationService::activateFromPaidOrder)
 * activates the plan and sets next_charge_at to the next cycle, after which the recurring
 * engine (DispatchDuePlansCommand / ChargeOrchestrator) bills every cycle until cancelled.
 *
 * Ownership boundary: this builds the plan ROW + asks the platform for the first-payment
 * PayPlus page; it never charges. The plan is born `awaiting_first_payment` with NO
 * succeeded payment and next_charge_at = null. Money law: the recurring amount comes from
 * the server-trusted ProductPriceResolver via the caller's recomputed price — the
 * storefront never sends an amount it controls. Tenant law: $shop is the HMAC-verified
 * shop ONLY; BelongsToShop auto-stamps shop_id and we forceFill the guarded shop_id/status.
 *
 * The hosted page is requested through the SAME PlatformInvoiceService seam the deposit
 * flow uses (WooCommerceDepositInvoiceService → generateLink), passing the first-cycle
 * amount as the page line item — so one PayPlus-page implementation serves both flows.
 */
final class RecurringPlanService
{
    // === CONSTANTS ===
    /** meta key holding the per-cycle recurring amount (mirrors the deposit's META_DEPOSIT_AMOUNT). */
    public const META_RECURRING_AMOUNT = DepositPlanService::META_DEPOSIT_AMOUNT;

    /**
     * Create the recurring plan + its first-payment PayPlus page; return the plan and the
     * hosted page URL the storefront redirects the shopper to.
     *
     * @param  array{
     *     product_gid: string, variant_gid: string, item_title: string,
     *     amount: float, frequency: BillingFrequency, interval_count?: int, currency: string,
     *     customer_email?: ?string, customer_name?: ?string, customer_phone?: ?string,
     *     external_customer_id?: ?string
     * }  $context
     * @return array{plan: InstallmentPlan, invoice_url: string}
     */
    public function create(Shop $shop, array $context): array
    {
        // Resolve the platform's hosted-page service up front — fail fast before we
        // create a plan we can't take a first payment for.
        $invoiceService = PlatformInvoiceServiceFactory::for($shop);
        if ($invoiceService === null) {
            throw new \RuntimeException("No first-payment page service for platform [{$shop->platform}].");
        }

        $amount = round((float) $context['amount'], 2);
        if ($amount <= 0) {
            throw new \RuntimeException('Recurring subscription amount must be positive.');
        }

        $frequency = $context['frequency'];
        $intervalCount = max(1, (int) ($context['interval_count'] ?? 1));
        $currency = (string) $context['currency'];

        $plan = $this->buildPlanRow($shop, $context, $amount, $frequency, $intervalCount, $currency);

        // Request the FIRST-PAYMENT PayPlus page (reusing the deposit-page seam — the
        // line item's amount is the first cycle's amount).
        $invoice = $invoiceService->createDepositInvoice($plan, [
            'title' => (string) $context['item_title'],
            'deposit_amount' => $amount,
            'quantity' => 1,
            'variant_gid' => (string) $context['variant_gid'],
        ]);

        // Persist the page linkage so the paid-order callback can find + activate the plan.
        $meta = (array) ($plan->meta ?? []);
        $meta[DepositPlanService::META_DRAFT_GID] = $invoice['external_gid'];
        $meta[DepositPlanService::META_DRAFT_ID] = $invoice['external_ref'];
        $meta[DepositPlanService::META_INVOICE_URL] = $invoice['invoice_url'];
        $plan->meta = $meta;
        $plan->shopify_order_id = $invoice['external_ref'] !== '' ? $invoice['external_ref'] : $plan->shopify_order_id;
        $plan->save();

        Timeline::record(
            kind: 'recurring_plan_created',
            details: [
                'plan_public_id' => $plan->public_id,
                'amount' => $amount,
                'frequency' => $frequency->value,
                'interval_count' => $intervalCount,
                'external_ref' => $invoice['external_ref'],
            ],
            planId: $plan->getKey(),
            shopId: (int) $shop->getKey(),
        );

        return ['plan' => $plan, 'invoice_url' => $invoice['invoice_url']];
    }

    /**
     * Create a recurring plan whose FIRST payment is collected EXTERNALLY (W17) — on the
     * WooCommerce gateway checkout page for the whole cart, NOT on a second PayPlus page this
     * service mints. Returns just the awaiting_first_payment plan; WooGatewayFinalizer activates it
     * (PlanActivationService) once the gateway order is paid, using the plan's stored per-cycle
     * amount. Same money law (server-trusted amount) + tenant law (shop_id forceFilled from $shop).
     *
     * @param  array{
     *     product_gid: string, variant_gid: string, item_title?: string,
     *     amount: float, frequency: BillingFrequency, interval_count?: int, currency: string,
     *     customer_email?: ?string, customer_name?: ?string, customer_phone?: ?string,
     *     external_customer_id?: ?string
     * }  $context
     */
    public function createAwaitingExternalPayment(Shop $shop, array $context): InstallmentPlan
    {
        $amount = round((float) $context['amount'], 2);
        if ($amount <= 0) {
            throw new \RuntimeException('Recurring subscription amount must be positive.');
        }

        $plan = $this->buildPlanRow(
            $shop,
            $context,
            $amount,
            $context['frequency'],
            max(1, (int) ($context['interval_count'] ?? 1)),
            (string) $context['currency'],
        );

        Timeline::record(
            kind: 'recurring_plan_created',
            details: [
                'plan_public_id' => $plan->public_id,
                'amount' => $amount,
                'frequency' => $context['frequency']->value,
                'interval_count' => (int) $plan->interval_count,
                'source' => 'wc_cart_gateway', // first payment on the WC checkout page
            ],
            planId: $plan->getKey(),
            shopId: (int) $shop->getKey(),
        );

        return $plan;
    }

    /**
     * Build the awaiting_first_payment recurring plan ROW (shared by create() and
     * createAwaitingExternalPayment()). No page, no charge — money law: the amount is the
     * server-trusted per-cycle price; tenant law: shop_id is forceFilled from $shop.
     *
     * @param  array<string, mixed>  $context
     */
    private function buildPlanRow(Shop $shop, array $context, float $amount, BillingFrequency $frequency, int $intervalCount, string $currency): InstallmentPlan
    {
        return DB::transaction(function () use ($shop, $context, $amount, $frequency, $intervalCount, $currency): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill([
                'customer_id' => null,
                'shopify_customer_id' => $context['external_customer_id'] ?? null,
                'external_customer_id' => $context['external_customer_id'] ?? null,
                'shopify_variant_id' => ProductPriceResolver::numericId((string) $context['variant_gid']) ?: null,
                'shopify_product_id' => ProductPriceResolver::numericId((string) $context['product_gid']) ?: null,
                'external_variant_id' => ProductPriceResolver::numericId((string) $context['variant_gid']) ?: null,
                'external_product_id' => ProductPriceResolver::numericId((string) $context['product_gid']) ?: null,
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                // For an open-ended recurring plan total_amount mirrors the cycle amount
                // (there is no finite total to pay down); installment_amount is what we bill.
                'total_amount' => $amount,
                'total_charged' => 0,
                'installment_amount' => $amount,
                'currency' => $currency,
                'billing_frequency' => $frequency->value,
                'interval_count' => $intervalCount,
                // next_charge_at stays NULL until the first payment activates the plan.
                'next_charge_at' => null,
                'requires_manual_payment' => false,
                'public_id' => (string) Str::ulid(),
                'customer_email' => $context['customer_email'] ?? null,
                'customer_name' => $context['customer_name'] ?? null,
                'customer_phone' => $context['customer_phone'] ?? null,
                'meta' => [
                    // The first-payment amount the activation callback records as paid.
                    self::META_RECURRING_AMOUNT => $amount,
                    // Kept so a later accounting document can name the product the
                    // customer actually bought (InstallmentPlan::itemTitle()).
                    InstallmentPlan::META_ITEM_TITLE => (string) ($context['item_title'] ?? ''),
                ],
            ]);

            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
            ])->save();

            return $plan;
        });
    }
}
