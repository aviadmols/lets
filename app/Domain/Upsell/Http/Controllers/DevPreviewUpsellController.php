<?php

namespace App\Domain\Upsell\Http\Controllers;

use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\UpsellSignedUrlService;
use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * DEV-ONLY preview of the thank-you upsell widget for a single offer — the
 * "View post-purchase" button in the Configure-cross-sell drawer.
 *
 * In production the widget is reached ONLY through a SIGNED storefront link
 * (ThankYouUpsellController); there is no admin-session render path. This route
 * exists purely so the merchant (and the screenshot harness) can see exactly
 * what the customer sees, in the selected locale, while authoring.
 *
 * HARD GUARD: registered only when app()->isLocal() AND config('app.dev_tenant')
 * — the same gate DevAutoLogin uses — so it can never exist on a production
 * deploy. It renders ONLY a tenant-scoped offer (the global scope guarantees a
 * shop only previews its own offer; a foreign id 404s).
 */
final class DevPreviewUpsellController extends Controller
{
    public function __construct(
        private readonly UpsellSignedUrlService $urls,
    ) {}

    public function __invoke(int $offer): View
    {
        // DEV-ONLY tool: this storefront route has no admin session and no bound
        // tenant, so we resolve the offer's shop first (global scope bypassed —
        // sanctioned only because this route is hard-gated to local+dev_tenant),
        // then render the rest strictly inside that tenant's scope.
        $shopId = UpsellFlowOffer::query()
            ->withoutGlobalScopes()
            ->whereKey($offer)
            ->value('shop_id');

        if ($shopId === null) {
            throw new NotFoundHttpException('Unknown offer.');
        }

        $shop = Shop::query()->findOrFail((int) $shopId);

        return Tenant::run($shop, function () use ($offer, $shop): View {
            $offerModel = UpsellFlowOffer::query()->with('flow')->find($offer);

            if ($offerModel === null || $offerModel->flow === null) {
                throw new NotFoundHttpException('Unknown offer.');
            }

            $flow = $offerModel->flow;

            // Synthetic, non-charging preview context. The accept/decline links are
            // real signed links so the preview is faithful, but no event is recorded
            // and nothing is charged until the customer actually clicks on a live
            // thank-you page.
            $parentOrderId = 'preview';
            $customerRef = 'preview';

            return view('upsell::storefront.widget', [
                'shop' => $shop,
                'flow' => $flow,
                'offer' => $offerModel,
                'price' => $offerModel->discountedPrice(),
                'basePrice' => round((float) $offerModel->base_price, 2),
                'currency' => (string) config('payplus.currency', 'ILS'),
                'acceptUrl' => $this->urls->acceptUrl($flow, $offerModel, $parentOrderId, $customerRef),
                'declineUrl' => $this->urls->declineUrl($flow, $offerModel, $parentOrderId, $customerRef),
            ]);
        });
    }
}
