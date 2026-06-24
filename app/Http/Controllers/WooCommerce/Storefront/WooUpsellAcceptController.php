<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Upsell\AcceptUpsellRequest;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\UpsellChargeResult;
use App\Domain\Upsell\UpsellChargeService;
use App\Services\WooCommerce\Orders\WooUpsellChildOrderService;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/upsell/accept
 *
 * The WooCommerce analogue of AcceptUpsellApiController. The plugin's thank-you widget
 * POSTs (server-signed HMAC) the flow_id/offer_id/parent_order/customer it got from the
 * offer endpoint. We REUSE UpsellChargeService::accept VERBATIM — it charges the saved
 * PayPlus token (no card re-entry), ENFORCES upsell consent (fail closed), and is
 * idempotent on the deterministic upsell ledger key, so a double-click collapses to ONE
 * charge. Its Shopify draft-order child factory degrades to null for a WC shop, so it
 * charges + records but creates NO Shopify order; we then create the linked PAID WC child
 * order here (a RECORD of money that already moved — never a second charge).
 *
 * Tenant law: the shop is the HMAC-verified shop; the flow/offer are resolved under the
 * bound tenant (a signed call for shop A can never resolve shop B's flow). Money law:
 * the amount is the SERVER-computed offer price; the client never sends an amount.
 */
final class WooUpsellAcceptController extends WooStorefrontController
{
    public function __construct(
        private readonly UpsellChargeService $charges,
        private readonly WooUpsellChildOrderService $childOrders,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return Tenant::run($shop, function () use ($request, $shop): JsonResponse {
            // Tenant-scoped: these queries can only ever see THIS shop's flows/offers.
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

            // The engine charges + records (idempotent, consent-gated). Unchanged.
            $result = $this->charges->accept($shop, $req);

            // On a real charge (NOT the idempotent replay), record the linked paid WC
            // child order. A replay ("already_accepted") does not re-create the order —
            // the first accept created it; the charge never happened twice.
            $childOrderId = null;
            if ($result->result === UpsellChargeResult::RESULT_CHARGED) {
                $childOrderId = $this->childOrders->create(
                    shop: $shop,
                    offer: $offer,
                    parentOrderId: $req->parentOrderId,
                    amount: $offer->discountedPrice(),
                    currency: (string) ($offer->currency ?? config('payplus.currency', 'ILS')),
                    customerEmail: $req->customerEmail,
                );
            }

            return response()->json([
                'result' => $result->result,
                'charged' => $result->isCharged(),
                'transaction_uid' => $result->transactionUid,
                'child_order_id' => $childOrderId,
            ], $this->statusFor($result));
        });
    }

    /** HTTP status mirrors the charge outcome (parity with AcceptUpsellApiController). */
    private function statusFor(UpsellChargeResult $result): int
    {
        return match ($result->result) {
            UpsellChargeResult::RESULT_CHARGED,
            UpsellChargeResult::RESULT_ALREADY => Response::HTTP_OK,
            UpsellChargeResult::RESULT_NO_CONSENT,
            UpsellChargeResult::RESULT_NO_METHOD => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_PAYMENT_REQUIRED, // charge_failed
        };
    }
}
