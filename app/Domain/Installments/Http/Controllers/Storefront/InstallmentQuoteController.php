<?php

namespace App\Domain\Installments\Http\Controllers\Storefront;

use App\Domain\Installments\DepositPlanService;
use App\Domain\Installments\InstallmentQuote;
use App\Domain\Installments\ProductPriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /proxy/installments/quote
 *
 * Returns the JSON schedule PREVIEW for the chosen knobs (down-payment %,
 * installments count, frequency, payment day). The price is resolved SERVER-SIDE
 * from the synced catalog cache via the variant GID — the storefront sends only
 * knobs + the variant GID, NEVER an amount. Every knob is clamped to its bounds in
 * InstallmentQuote::build, so a tampered request still yields a sane, in-bounds
 * schedule (or 422 when the variant isn't ours to price).
 *
 * This is a PREVIEW only — no plan, no invoice, no charge. The start endpoint
 * recomputes the identical quote before it commits anything.
 */
final class InstallmentQuoteController extends ProxyInstallmentController
{
    public function __construct(private readonly ProductPriceResolver $prices) {}

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $variantGid = (string) $request->input('variant_gid', '');
        $price = $this->prices->priceFor($variantGid);

        if ($price === null) {
            // We have no trusted price for this variant — fail closed (never quote a
            // client-supplied amount).
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
