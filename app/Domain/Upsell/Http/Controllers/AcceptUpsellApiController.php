<?php

namespace App\Domain\Upsell\Http\Controllers;

use App\Domain\Upsell\AcceptUpsellRequest;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\UpsellChargeResult;
use App\Domain\Upsell\UpsellChargeService;
use App\Domain\Upsell\UpsellSignedUrlService;
use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * JSON twin of AcceptUpsellController for the checkout/post-purchase EXTENSIONS
 * (they consume JSON, not an HTML view). It is the SAME money flow — it just maps
 * the existing UpsellChargeService::accept onto a JSON response.
 *
 * Auth is identical to the HTML accept: the route is `signed`, and the GET offer
 * endpoint handed the extension a SIGNED accept URL (shop/flow/offer/parent_order/
 * customer all inside the signature). So:
 *   - the signature is the auth (defence-in-depth re-check here),
 *   - the tenant is bound from the SIGNED shop id (never inferred),
 *   - the amount is recomputed server-side from the offer (UpsellChargeService /
 *     UpsellFlowOffer::discountedPrice) — the client amount is never read,
 *   - the charge is idempotent on the deterministic upsell ledger key, so a
 *     double-tap "Accept" in the extension collapses to exactly ONE charge.
 *
 * This controller adds NO new charge logic; the engine is untouched.
 */
final class AcceptUpsellApiController extends Controller
{
    public function __construct(
        private readonly UpsellChargeService $charges,
        private readonly UpsellSignedUrlService $urls,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // The `signed` middleware already verified the signature; re-assert here.
        abort_unless($request->hasValidSignature(), 403);

        $shop = $this->resolveShop($request);

        return Tenant::run($shop, function () use ($request, $shop): JsonResponse {
            [$flow, $offer] = $this->resolveFlowAndOffer($request);

            $req = new AcceptUpsellRequest(
                flow: $flow,
                offer: $offer,
                parentOrderId: (string) $request->query('parent_order', ''),
                customerRef: (string) $request->query('customer', ''),
                customerEmail: $request->query('email') !== null ? (string) $request->query('email') : null,
            );

            $result = $this->charges->accept($shop, $req);

            // If the accept branch points at a follow-up offer, sign its action
            // links so the extension can present "one more offer" with no re-entry.
            $nextOfferUrl = $result->nextOffer !== null
                ? $this->urls->acceptUrl($flow, $result->nextOffer, $req->parentOrderId, $req->customerRef)
                : null;

            return response()->json([
                'result' => $result->result,
                'charged' => $result->isCharged(),
                'transaction_uid' => $result->transactionUid,
                'next_offer' => $this->nextOfferJson($result, $nextOfferUrl),
            ], $this->statusFor($result));
        });
    }

    private function resolveShop(Request $request): Shop
    {
        $shop = Shop::query()->find((int) $request->query('shop'));

        if ($shop === null) {
            throw new NotFoundHttpException('Unknown shop.');
        }

        return $shop;
    }

    /** @return array{0: UpsellFlow, 1: UpsellFlowOffer} */
    private function resolveFlowAndOffer(Request $request): array
    {
        // Under the bound tenant these queries are shop-scoped — a signed link for
        // shop A can never resolve shop B's flow.
        $flow = UpsellFlow::query()->findOrFail((int) $request->query('flow'));
        $offer = UpsellFlowOffer::query()
            ->where('flow_id', $flow->getKey())
            ->findOrFail((int) $request->query('offer'));

        return [$flow, $offer];
    }

    /** @return array<string, mixed>|null */
    private function nextOfferJson(UpsellChargeResult $result, ?string $nextOfferUrl): ?array
    {
        if ($result->nextOffer === null) {
            return null;
        }

        return [
            'offer_id' => (int) $result->nextOffer->getKey(),
            'title' => (string) ($result->nextOffer->offer_title ?? __('upsell.offer_default_title')),
            'price' => $result->nextOffer->discountedPrice(),
            'accept_url' => $nextOfferUrl,
        ];
    }

    /**
     * HTTP status mirrors the charge outcome so the extension can branch without
     * parsing copy: charged/already → 200, declined-but-no-charge states → 200
     * with a result string the UI reads, hard failure → 402 (payment).
     */
    private function statusFor(UpsellChargeResult $result): int
    {
        return match ($result->result) {
            UpsellChargeResult::RESULT_CHARGED,
            UpsellChargeResult::RESULT_ALREADY => 200,
            UpsellChargeResult::RESULT_NO_CONSENT,
            UpsellChargeResult::RESULT_NO_METHOD => 422,
            default => 402, // charge_failed
        };
    }
}
