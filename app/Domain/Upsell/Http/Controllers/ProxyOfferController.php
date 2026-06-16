<?php

namespace App\Domain\Upsell\Http\Controllers;

use App\Domain\Upsell\PurchaseContext;
use App\Domain\Upsell\UpsellResolver;
use App\Domain\Upsell\UpsellSignedUrlService;
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
    // === CONSTANTS ===
    /** Default display currency when neither offer nor config pins one. */
    private const DEFAULT_CURRENCY = 'ILS';

    public function __construct(
        private readonly UpsellResolver $resolver,
        private readonly UpsellSignedUrlService $urls,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Shop $shop The proxy middleware resolved + bound this. */
        $shop = $request->attributes->get(VerifyShopifyAppProxy::ATTR_SHOP);

        // Defence in depth: the bound tenant MUST equal the proxy-resolved shop.
        if (! $shop instanceof Shop || Tenant::id() !== (int) $shop->getKey()) {
            return response()->json(['offer' => null, 'reason' => 'no_tenant'], 200);
        }

        $context = $this->buildContext($request, $shop);
        $resolution = $this->resolver->resolve($context);

        if ($resolution === null) {
            return response()->json(['offer' => null], 200);
        }

        $offer = $resolution->offer;
        $flow = $resolution->flow;
        $currency = (string) ($offer->currency ?? config('payplus.currency', self::DEFAULT_CURRENCY));

        return response()->json([
            'offer' => [
                'flow_id' => (int) $flow->getKey(),
                'offer_id' => (int) $offer->getKey(),
                'title' => (string) ($offer->offer_title ?? __('upsell.offer_default_title')),
                'product_gid' => $offer->offer_product_gid,
                'variant_gid' => $offer->offer_variant_gid,
                // Server-computed money truth — never trust a client amount.
                'price' => $resolution->discountedPrice(),
                'base_price' => round((float) $offer->base_price, 2),
                'currency' => $currency,
            ],
            // Signed one-click action links — the extension POSTs/redirects here.
            // The signature carries shop/flow/offer/parent_order/customer so the
            // accept controller rebuilds the deterministic key with no client trust.
            //   accept_api_url → JSON (extensions);  accept_url → HTML (proxy widget).
            'accept_api_url' => $this->urls->acceptApiUrl($flow, $offer, $context->parentOrderId, $context->customerRef),
            'accept_url' => $this->urls->acceptUrl($flow, $offer, $context->parentOrderId, $context->customerRef),
            'decline_url' => $this->urls->declineUrl($flow, $offer, $context->parentOrderId, $context->customerRef),
        ], 200);
    }

    private function buildContext(Request $request, Shop $shop): PurchaseContext
    {
        return new PurchaseContext(
            shopId: (int) $shop->getKey(),
            parentOrderId: (string) $request->query('parent_order', ''),
            customerRef: (string) $request->query('customer', ''),
            orderSubtotal: (float) $request->query('subtotal', 0),
            purchasedProductGids: $this->csv($request->query('products')),
            purchasedCollectionGids: $this->csv($request->query('collections')),
            purchasedTags: $this->csv($request->query('tags')),
            customerEmail: $request->query('email') !== null ? (string) $request->query('email') : null,
        );
    }

    /** @return list<string> */
    private function csv(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
