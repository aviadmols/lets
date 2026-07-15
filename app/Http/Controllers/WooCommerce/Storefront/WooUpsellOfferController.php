<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Upsell\PurchaseContext;
use App\Domain\Upsell\UpsellResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/woocommerce/upsell/offer
 *
 * The WooCommerce analogue of ProxyOfferController / OfferResponder. The plugin's
 * thank-you page calls this (server-signed HMAC) with the just-completed order's facts
 * (parent order id, customer ref, purchased product ids, subtotal). We resolve the
 * eligible offer via the SHARED UpsellResolver (which records the funnel impression) and
 * return the offer JSON. Unlike Shopify, we return NO signed accept URL — the plugin
 * re-signs its own HMAC call to /upsell/accept, passing flow_id/offer_id/parent_order/
 * customer back, so the shop is always the HMAC-verified shop.
 *
 * Money law: the price is the SERVER-computed discounted price (UpsellResolution::
 * discountedPrice) — the client never sends or influences the amount. Tenant law: the
 * shop is the HMAC-verified shop (the middleware bound it); the resolver asserts the
 * context shop equals the bound tenant, so a mismatch fails closed.
 */
final class WooUpsellOfferController extends WooStorefrontController
{
    // === CONSTANTS ===
    /** Default display currency when neither offer nor config pins one. */
    private const DEFAULT_CURRENCY = 'ILS';

    public function __construct(private readonly UpsellResolver $resolver) {}

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['offer' => null, 'reason' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $context = new PurchaseContext(
            shopId: (int) $shop->getKey(),
            parentOrderId: (string) $request->query('parent_order', ''),
            customerRef: (string) $request->query('customer', ''),
            orderSubtotal: (float) $request->query('subtotal', 0),
            purchasedProductGids: $this->csv($request->query('products')),
            purchasedCollectionGids: $this->csv($request->query('collections')),
            purchasedTags: $this->csv($request->query('tags')),
            customerEmail: $request->query('email') !== null ? (string) $request->query('email') : null,
        );

        $resolution = $this->resolver->resolve($context);
        if ($resolution === null) {
            return response()->json(['offer' => null], Response::HTTP_OK);
        }

        $offer = $resolution->offer;
        $flow = $resolution->flow;
        $currency = (string) ($offer->currency ?? config('payplus.currency', self::DEFAULT_CURRENCY));

        // The REAL catalog product behind the offer — so the card can show its name + image
        // instead of only the merchant's headline text.
        $product = $offer->resolveProduct();

        return response()->json([
            'offer' => [
                'flow_id' => (int) $flow->getKey(),
                'offer_id' => (int) $offer->getKey(),
                // headline = the merchant's marketing copy; product_name = the real product.
                'title' => (string) ($offer->headline ?: $offer->offer_title ?: __('upsell.offer_default_title')),
                'product_name' => $product?->title,
                'product_image' => $product?->image_url,
                'cta' => (string) ($offer->accept_cta ?: __('upsell.accept_cta')),
                // Server-computed money truth — never trust a client amount.
                'price' => $resolution->discountedPrice(),
                'base_price' => round((float) $offer->base_price, 2),
                'currency' => $currency,
                // Echo back the order facts the plugin re-sends on accept (the plugin
                // re-signs the HMAC accept call; the shop stays the verified shop).
                'parent_order' => $context->parentOrderId,
                'customer' => $context->customerRef,
            ],
        ], Response::HTTP_OK);
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
