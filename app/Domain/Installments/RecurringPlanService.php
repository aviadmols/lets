<?php

namespace App\Domain\Installments;

use App\Models\InstallmentPlan;
use App\Models\ProductSubscriptionPlan;
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
     * Create a recurring plan for a customer who is ALREADY known to us and already has a
     * saved PayPlus token — the account-area offer path (W-Offers). No hosted page, no
     * checkout: the caller charges (or schedules) the saved token itself.
     *
     * The identity and the payment method are the CALLER's to supply, and in practice they
     * are copied verbatim from the subscription the offer was taken from. That is not an
     * implementation detail but the whole reason this signature exists: an imported member's
     * reference is the UUID their previous system gave them (shopify_customer_id), with
     * customer_id null, and the consent gate matches on exactly those two columns. A plan
     * built from the visitor's WordPress user id instead would be un-chargeable forever.
     *
     * Born `awaiting_first_payment` like every other recurring plan, with next_charge_at set
     * to the day the caller decided the first charge falls — today for an immediate switch,
     * the old plan's renewal date for one that waits. The existing scheduler bills it either
     * way; there is no second mechanism.
     *
     * @param  array{
     *     product_gid: string, variant_gid: string, item_title?: string,
     *     amount: float, frequency: BillingFrequency, interval_count?: int, currency: string,
     *     template?: ProductSubscriptionPlan, regular_amount?: float,
     *     customer_email?: ?string, customer_name?: ?string, customer_phone?: ?string,
     *     customer_id?: ?int, shopify_customer_id?: ?string, external_customer_id?: ?string,
     *     payment_method_id?: ?int, first_charge_at?: mixed, meta?: array<string, mixed>,
     *     source?: string
     * }  $context
     */
    public function createForCustomer(Shop $shop, array $context): InstallmentPlan
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
                'first_charge_at' => $plan->next_charge_at?->toDateString(),
                'source' => (string) ($context['source'] ?? 'account_offer'),
            ],
            planId: $plan->getKey(),
            shopId: (int) $shop->getKey(),
        );

        return $plan;
    }

    /**
     * Build the awaiting_first_payment recurring plan ROW (shared by create(),
     * createAwaitingExternalPayment() and createForCustomer()). No page, no charge — money
     * law: the amount is the server-trusted per-cycle price; tenant law: shop_id is
     * forceFilled from $shop.
     *
     * The identity / payment-method / first-charge-date / extra-meta keys are OPTIONAL and
     * default to exactly what the two checkout paths produced before they existed, so those
     * paths keep writing byte-identical rows (RecurringPlanServiceTest asserts it).
     *
     * Pricing snapshot (consent law): when the caller resolved a template, its
     * pricing_mode / discount_cycles / min_cycles_before_exit land HERE as the
     * plan's own copy, together with regular_amount (the undiscounted per-cycle
     * amount, quantity included) — a later template edit must never
     * retroactively reprice a live plan, nor re-negotiate how long its
     * subscriber is tied to it.
     *
     * @param  array<string, mixed>  $context
     */
    private function buildPlanRow(Shop $shop, array $context, float $amount, BillingFrequency $frequency, int $intervalCount, string $currency): InstallmentPlan
    {
        $template = $context['template'] ?? null;
        $template = $template instanceof ProductSubscriptionPlan ? $template : null;
        $regular = round((float) ($context['regular_amount'] ?? 0), 2);

        return DB::transaction(function () use ($shop, $context, $amount, $frequency, $intervalCount, $currency, $template, $regular): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill([
                'pricing_mode' => $template?->pricing_mode,
                'discount_cycles' => $template?->introWindow(),
                // The commitment, snapshotted for the same reason as the price:
                // raising the template's minimum changes what the shop OFFERS,
                // never what a live subscriber already agreed to.
                'min_cycles_before_exit' => $template?->minCyclesBeforeExit() ?: null,
                'regular_amount' => $regular > 0 ? $regular : null,
                'product_subscription_plan_id' => $template?->getKey(),
                // Identity: the checkout paths know only an external customer id;
                // the account-offer path copies BOTH columns off the source plan,
                // because those two are what the consent gate matches on.
                'customer_id' => $context['customer_id'] ?? null,
                'shopify_customer_id' => $context['shopify_customer_id'] ?? $context['external_customer_id'] ?? null,
                'external_customer_id' => $context['external_customer_id'] ?? null,
                // The saved card this plan bills. Null at checkout (the token does
                // not exist yet); the source plan's token for an offer acceptance.
                'payment_method_id' => $context['payment_method_id'] ?? null,
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
                // NULL until the first payment activates the plan — unless the caller
                // already knows when the first charge falls (an offer acceptance does:
                // today, or the day the replaced plan would have renewed).
                'next_charge_at' => $context['first_charge_at'] ?? null,
                'requires_manual_payment' => false,
                'public_id' => (string) Str::ulid(),
                'customer_email' => $context['customer_email'] ?? null,
                'customer_name' => $context['customer_name'] ?? null,
                'customer_phone' => $context['customer_phone'] ?? null,
                'meta' => array_merge([
                    // The first-payment amount the activation callback records as paid.
                    self::META_RECURRING_AMOUNT => $amount,
                    // Kept so a later accounting document can name the product the
                    // customer actually bought (InstallmentPlan::itemTitle()).
                    InstallmentPlan::META_ITEM_TITLE => (string) ($context['item_title'] ?? ''),
                ], (array) ($context['meta'] ?? [])),
            ]);

            // A plan is normally BORN awaiting its first payment — a checkout has
            // not been paid yet, and the payment is what promotes it to active.
            //
            // A SCHEDULED SWITCH is the exception: the subscriber is already
            // paying, on a card we already hold, and this row only continues that
            // subscription under a new price from the next renewal. There is no
            // first payment to wait for — the promotion hook lives in the charge
            // path, so a row born "awaiting" here would sit mislabelled for a
            // whole cycle, and every gate that asks "does this person have a
            // subscription?" would answer no while they plainly do.
            $bornActive = (bool) ($context['born_active'] ?? false);

            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
            ])->save();

            if ($bornActive) {
                $plan->transitionTo(PlanStatus::ACTIVE, ['action' => 'switch_scheduled']);
            }

            return $plan;
        });
    }
}
