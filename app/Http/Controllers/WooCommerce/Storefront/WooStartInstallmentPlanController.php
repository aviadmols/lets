<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Installments\DepositPlanService;
use App\Domain\Installments\InstallmentQuote;
use App\Domain\Installments\ProductPriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/installments/start
 *
 * The WooCommerce analogue of StartInstallmentPlanController. Creates the DEPOSIT +
 * installments plan (status awaiting_first_payment, tenant stamped) and the PayPlus
 * HOSTED PAGE (via WooCommerceDepositInvoiceService → generateLink), then returns the
 * page URL the plugin redirects the browser to. The shopper pays the deposit on the
 * PayPlus page; PayPlus then calls our callback, which activates the plan.
 *
 * Money law (re-stated at the commit point): the price is resolved SERVER-SIDE from the
 * synced WC catalog cache and the quote is RECOMPUTED here from clamped knobs — the
 * preview the client last saw is advisory. Tenant law: $shop is the HMAC-verified shop
 * only; DepositPlanService stamps shop_id from it. No charge happens here — the deposit
 * is collected on the returned PayPlus page (ledger-before-charge preserved).
 */
final class WooStartInstallmentPlanController extends WooStorefrontController
{
    public function __construct(
        private readonly ProductPriceResolver $prices,
        private readonly DepositPlanService $plans,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Bare WC ids (the plugin sends them); ProductPriceResolver::numericId() accepts
        // them. We funnel them through the same product_gid/variant_gid context keys the
        // engine reads — DepositPlanService strips numeric ids from either shape.
        $productId = (string) $request->input('product_id', $request->input('product_gid', ''));
        $variantId = (string) $request->input('variant_id', $request->input('variant_gid', ''));

        $resolved = $this->prices->resolve($productId, $variantId);
        if ($resolved === null) {
            return response()->json(['error' => 'variant_not_priceable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $price = round((float) $resolved['variant']->price, 2);

        // RECOMPUTE the quote server-side from clamped knobs — authoritative money truth.
        $quote = InstallmentQuote::build(
            totalAmount: $price,
            depositPercent: (int) $request->input('deposit_percent', InstallmentQuote::DEFAULT_DEPOSIT_PERCENT),
            installments: (int) $request->input('installments', InstallmentQuote::DEFAULT_INSTALLMENTS),
            frequency: DepositPlanService::frequencyFrom($request->input('frequency')),
            paymentDay: (int) $request->input('payment_day', InstallmentQuote::DEFAULT_PAYMENT_DAY),
            currency: (string) ($request->input('currency') ?: config('payplus.currency', 'ILS')),
        );

        try {
            $result = $this->plans->create($shop, $quote, [
                'product_gid' => $productId,
                'variant_gid' => $variantId,
                'item_title' => $resolved['title'],
                'customer_email' => $this->cleanEmail($request->input('customer_email')),
                'customer_name' => $this->cleanString($request->input('customer_name')),
                'customer_phone' => $this->cleanString($request->input('customer_phone')),
            ]);
        } catch (\Throwable $e) {
            Log::error('woocommerce.installments.start_failed', [
                'shop_id' => $shop->getKey(),
                'variant_id' => $variantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'plan_creation_failed'], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'plan_public_id' => $result['plan']->public_id,
            'invoice_url' => $result['invoice_url'],
            'deposit_amount' => $quote->depositAmount,
            'currency' => $quote->currency,
        ], Response::HTTP_CREATED);
    }
}
