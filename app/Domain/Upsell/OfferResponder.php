<?php

namespace App\Domain\Upsell;

use App\Models\Shop;
use Illuminate\Http\Request;

/**
 * Builds the "eligible upsell offer" JSON for the storefront extensions, from the
 * purchase facts on the request query. ONE shape, two transports:
 *   - ProxyOfferController        (App-Proxy-signature authed) and
 *   - SessionTokenOfferController (session-token / JWT authed)
 * both delegate here, so the offer shape + signed action URLs are defined exactly
 * once and can never drift between the two seams.
 *
 * The shop is resolved by the CALLER's auth (proxy signature or verified JWT) and
 * passed in explicitly — this responder NEVER reads a shop id from client input.
 * It resolves under the bound tenant via UpsellResolver (records the impression)
 * and signs the accept/decline action links via UpsellSignedUrlService.
 *
 * Money law: the price is the SERVER-computed discounted price
 * (UpsellResolution::discountedPrice) — the client never sends or influences it.
 */
final class OfferResponder
{
    // === CONSTANTS ===
    /** Default display currency when neither offer nor config pins one. */
    private const DEFAULT_CURRENCY = 'ILS';

    public function __construct(
        private readonly UpsellResolver $resolver,
        private readonly UpsellSignedUrlService $urls,
    ) {}

    /**
     * Resolve the offer for $shop from the request's purchase context and return
     * the JSON payload (offer + SIGNED action URLs), or `['offer' => null]` when
     * nothing matches. The caller has already bound $shop as the tenant.
     *
     * @return array<string, mixed>
     */
    public function respond(Request $request, Shop $shop): array
    {
        $context = $this->buildContext($request, $shop);
        $resolution = $this->resolver->resolve($context);

        if ($resolution === null) {
            return ['offer' => null];
        }

        $offer = $resolution->offer;
        $flow = $resolution->flow;
        $currency = (string) ($offer->currency ?? config('payplus.currency', self::DEFAULT_CURRENCY));

        return [
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
        ];
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
