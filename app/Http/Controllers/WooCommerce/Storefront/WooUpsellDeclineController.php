<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Upsell\AcceptUpsellRequest;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\UpsellChargeService;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/upsell/decline
 *
 * The shopper clicked "No thanks" on the thank-you offer. No money moves — we just record the
 * DECLINED funnel event (so conversion/decline analytics are complete for WooCommerce, as they
 * already are for Shopify) and route to the decline branch. Reuses UpsellChargeService::decline
 * verbatim. Tenant law: flow/offer resolve under the HMAC-verified shop only.
 */
final class WooUpsellDeclineController extends WooStorefrontController
{
    public function __construct(private readonly UpsellChargeService $charges) {}

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return Tenant::run($shop, function () use ($request, $shop): JsonResponse {
            $flow = UpsellFlow::query()->find((int) $request->input('flow_id'));
            $offer = $flow !== null
                ? UpsellFlowOffer::query()->where('flow_id', $flow->getKey())->find((int) $request->input('offer_id'))
                : null;

            if ($flow === null || $offer === null) {
                return response()->json(['error' => 'offer_not_found'], Response::HTTP_NOT_FOUND);
            }

            $req = new AcceptUpsellRequest(
                flow: $flow,
                offer: $offer,
                parentOrderId: (string) $request->input('parent_order', ''),
                customerRef: (string) $request->input('customer', ''),
                customerEmail: $this->cleanEmail($request->input('email')),
            );

            $result = $this->charges->decline((int) $shop->getKey(), $req);

            return response()->json(['result' => $result->result], Response::HTTP_OK);
        });
    }
}
