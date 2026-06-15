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
 * DECLINE of a thank-you-page upsell. Never charges. Records the `declined`
 * funnel event and routes to the decline branch (the next offer, if any).
 * Same signed-route auth + tenant binding as the accept controller.
 */
final class DeclineUpsellController extends Controller
{
    public function __construct(
        private readonly UpsellChargeService $charges,
        private readonly UpsellSignedUrlService $urls,
    ) {}

    public function __invoke(Request $request): View
    {
        abort_unless($request->hasValidSignature(), 403);

        $shop = $this->resolveShop($request);

        return Tenant::run($shop, function () use ($request, $shop): View {
            $flow = UpsellFlow::query()->findOrFail((int) $request->query('flow'));
            $offer = UpsellFlowOffer::query()
                ->where('flow_id', $flow->getKey())
                ->findOrFail((int) $request->query('offer'));

            $req = new AcceptUpsellRequest(
                flow: $flow,
                offer: $offer,
                parentOrderId: (string) $request->query('parent_order', ''),
                customerRef: (string) $request->query('customer', ''),
            );

            $result = $this->charges->decline((int) $shop->getKey(), $req);

            $nextOfferUrl = $result->nextOffer !== null
                ? $this->urls->acceptUrl($flow, $result->nextOffer, $req->parentOrderId, $req->customerRef)
                : null;

            return view('upsell::storefront.result', [
                'shop' => $shop,
                'result' => $result,
                'offer' => $offer,
                'nextOffer' => $result->nextOffer,
                'nextOfferUrl' => $nextOfferUrl,
                'isSuccess' => false,
                'declined' => true,
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
}
