<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Installments\DepositPlanService;
use App\Domain\Installments\InstallmentQuote;
use App\Domain\Installments\ProductPriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/installments/quote
 *
 * The WooCommerce analogue of InstallmentQuoteController. Returns the schedule PREVIEW
 * for the chosen knobs (deposit %, installments, frequency, payment day). The price is
 * resolved SERVER-SIDE from the synced WC catalog cache by the WC variation id — the
 * plugin sends only knobs + the variation id, NEVER an amount. Every knob is clamped in
 * InstallmentQuote::build, so a tampered request still yields an in-bounds schedule (or
 * 422 when the variation isn't ours to price).
 *
 * Preview only — no plan, no PayPlus page, no charge. The start endpoint recomputes the
 * identical quote before it commits anything. The shop is the HMAC-verified shop.
 */
final class WooInstallmentQuoteController extends WooStorefrontController
{
    public function __construct(private readonly ProductPriceResolver $prices) {}

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // WooCommerce sends bare numeric ids; ProductPriceResolver::numericId() accepts
        // them as-is (and a gid:// too), so the same resolver works for both platforms.
        $variantId = (string) $request->input('variant_id', $request->input('variant_gid', ''));
        $price = $this->prices->priceFor($variantId);

        if ($price === null) {
            return response()->json(['error' => 'variant_not_priceable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $quote = InstallmentQuote::build(
            totalAmount: $price,
            depositPercent: (int) $request->input('deposit_percent', InstallmentQuote::DEFAULT_DEPOSIT_PERCENT),
            installments: (int) $request->input('installments', InstallmentQuote::DEFAULT_INSTALLMENTS),
            frequency: DepositPlanService::frequencyFrom($request->input('frequency')),
            paymentDay: (int) $request->input('payment_day', InstallmentQuote::DEFAULT_PAYMENT_DAY),
            currency: (string) ($request->input('currency') ?: config('payplus.currency', 'ILS')),
        );

        return response()->json(['quote' => $quote->toArray()], Response::HTTP_OK);
    }
}
