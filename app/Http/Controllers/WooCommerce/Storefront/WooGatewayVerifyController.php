<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Services\PayPlus\PayPlusCallbackVerifier;
use App\Services\WooCommerce\Orders\WooGatewayFinalizer;
use App\Services\WooCommerce\Orders\WooGatewayPageRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/gateway/verify  — verify-on-return (W16).
 *
 * The reliable confirmation of a gateway payment when PayPlus does NOT push refURL_callback (the
 * cause of orders stuck "pending"). The plugin calls this from the thank-you page (HMAC-signed)
 * with the order id + the page_request_uid it stored at checkout. We ask PayPlus directly whether
 * that page request was approved; if so, we finalise EXACTLY as the callback would — mark the WC
 * order paid + vault the reusable token — via the shared WooGatewayFinalizer (idempotent, so a
 * later push callback is a harmless no-op).
 *
 * Money law: we never trust a client-asserted status — the approval comes from PayPlus itself,
 * and it must be bound to THIS order: PayPlus's record of the page carries gw:{order_id}, and
 * what it collected covers the WC order total (WooGatewayFinalizer). A paid page of another,
 * cheaper order marks nothing.
 * Tenant law: the shop is the HMAC-verified shop.
 */
final class WooGatewayVerifyController extends WooStorefrontController
{
    public function __invoke(Request $request, PayPlusCallbackVerifier $verifier): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $orderId = (string) $request->input('order_id', '');
        $pageRequestUid = (string) $request->input('page_request_uid', '');
        if ($orderId === '' || $pageRequestUid === '') {
            return response()->json(['error' => 'invalid_request'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The page id is only a pointer: it arrives from the thank-you page, so a shopper can
        // swap in the id of a DIFFERENT paid page. PayPlus's record must carry THIS order's
        // marker (gw:{order}) at transaction level; the finalizer then checks the amount
        // against the WC order total. No unsigned field of anyone's is trusted here.
        $confirmation = $verifier->confirm(
            shop: $shop,
            callback: ['page_request_uid' => $pageRequestUid],
            expectedMoreInfo: WooGatewaySessionController::MORE_INFO_PREFIX.$orderId,
            signed: false,
            // The page WE opened for this order wins over the one the thank-you page names.
            ownPageRequestUid: WooGatewayPageRegistry::pageFor($shop, $orderId, $pageRequestUid),
        );

        if (! $confirmation->confirmed()) {
            // Not (yet) approved, or not this order's page — the order stays pending. Not an
            // error: the shopper may still be paying, or it genuinely failed. The push
            // callback (if it fires) also handles it.
            return response()->json(['ok' => true, 'paid' => false]);
        }

        $paid = app(WooGatewayFinalizer::class)->finalizePaid($shop, $orderId, $confirmation->body);

        Log::info('woocommerce.gateway.verified_on_return', [
            'shop_id' => $shop->getKey(), 'order_id' => $orderId, 'paid' => $paid,
        ]);

        return response()->json(['ok' => true, 'paid' => $paid]);
    }
}
