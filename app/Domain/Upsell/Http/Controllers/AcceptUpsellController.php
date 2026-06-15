<?php

namespace App\Domain\Upsell\Http\Controllers;

use App\Domain\Upsell\AcceptUpsellRequest;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\UpsellChargeService;
use App\Domain\Upsell\UpsellSignedUrlService;
use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One-click ACCEPT of a thank-you-page upsell. The route is signed (the
 * signature is the auth — the storefront has no admin session). The signed
 * params carry shop/flow/offer/parent_order/customer; the amount is recomputed
 * server-side from the offer, never read from the request body. NO card re-entry,
 * NO new payment page — the charge runs on the already-saved PayPlus token.
 *
 * The tenant is bound from the SIGNED shop id (never inferred from global state)
 * and cleared in finally so worker/request context never leaks.
 */
final class AcceptUpsellController extends Controller
{
    public function __construct(
        private readonly UpsellChargeService $charges,
        private readonly UpsellSignedUrlService $urls,
    ) {}

    public function __invoke(Request $request): View
    {
        // Reject tampered/expired links up front (Laravel verifies the signature
        // via the `signed` middleware on the route; this is defence in depth).
        abort_unless($request->hasValidSignature(), 403);

        $shop = $this->resolveShop($request);

        return Tenant::run($shop, function () use ($request, $shop): View {
            [$flow, $offer] = $this->resolveFlowAndOffer($request, $shop);

            $req = new AcceptUpsellRequest(
                flow: $flow,
                offer: $offer,
                parentOrderId: (string) $request->query('parent_order', ''),
                customerRef: (string) $request->query('customer', ''),
                customerEmail: $request->query('email') !== null ? (string) $request->query('email') : null,
            );

            $result = $this->charges->accept($shop, $req);

            // When the accept branch points to another offer, sign its action
            // links so "see one more offer" continues the flow with no re-entry.
            $nextOfferUrl = $result->nextOffer !== null
                ? $this->urls->acceptUrl($flow, $result->nextOffer, $req->parentOrderId, $req->customerRef)
                : null;

            return view('upsell::storefront.result', [
                'shop' => $shop,
                'result' => $result,
                'offer' => $offer,
                'nextOffer' => $result->nextOffer,
                'nextOfferUrl' => $nextOfferUrl,
                'isSuccess' => $result->isCharged(),
                'parentOrderId' => $req->parentOrderId,
                'customerRef' => $req->customerRef,
            ]);
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
    private function resolveFlowAndOffer(Request $request, Shop $shop): array
    {
        // Under the bound tenant, these queries are shop-scoped — a signed link
        // for shop A can never resolve shop B's flow (the scope + the explicit
        // shop both pin it).
        $flow = UpsellFlow::query()->findOrFail((int) $request->query('flow'));
        $offer = UpsellFlowOffer::query()
            ->where('flow_id', $flow->getKey())
            ->findOrFail((int) $request->query('offer'));

        return [$flow, $offer];
    }
}
