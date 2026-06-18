<?php

namespace App\Domain\Upsell\Http\Controllers;

use App\Domain\Upsell\OfferResponder;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyShopifyAppProxy;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET "eligible upsell offer" for the post-purchase + thank-you/order-status
 * extensions. The extension calls this through the Shopify App Proxy
 * (/apps/payplus/upsell/offer); VerifyShopifyAppProxy has ALREADY proven the
 * request is Shopify-signed, resolved the Shop from the verified `shop` param, and
 * bound the Tenant. So here we trust the shop, and ONLY the shop.
 *
 * Returns the offer JSON to render plus SIGNED accept/decline action URLs (so the
 * extension never has to know the shop id or recompute money — it just POSTs to
 * the signed accept URL on accept). Returns `{ offer: null }` when nothing matches.
 *
 * Money law: the price returned is the SERVER-computed discounted price
 * (UpsellFlowOffer::discountedPrice) — the client never sends or influences the
 * amount. Resolving records the funnel impression (the extension is about to show
 * the offer), exactly like the thank-you widget controller.
 */
final class ProxyOfferController extends Controller
{
    public function __construct(private readonly OfferResponder $responder) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Shop $shop The proxy middleware resolved + bound this. */
        $shop = $request->attributes->get(VerifyShopifyAppProxy::ATTR_SHOP);

        // Defence in depth: the bound tenant MUST equal the proxy-resolved shop.
        if (! $shop instanceof Shop || Tenant::id() !== (int) $shop->getKey()) {
            return response()->json(['offer' => null, 'reason' => 'no_tenant'], 200);
        }

        // The shared responder builds the context, resolves under the bound tenant
        // (recording the impression), and shapes the offer JSON + signed URLs.
        return response()->json($this->responder->respond($request, $shop), 200);
    }
}
