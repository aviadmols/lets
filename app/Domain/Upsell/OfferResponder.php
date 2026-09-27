<?php

namespace App\Domain\Upsell;

use App\Domain\Upsell\Verification\ShopifyParentOrderVerifier;
use App\Domain\Upsell\Verification\VerifiedPurchase;
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

    /** No shopper was authenticated by the caller (a guest, or no proof of who is asking). */
    public const REASON_NO_CUSTOMER = 'no_verified_customer';

    /** The order is not this shopper's, not in this store, cancelled, or not recent. */
    public const REASON_UNVERIFIED_ORDER = 'unverified_order';

    public function __construct(
        private readonly UpsellResolver $resolver,
        private readonly UpsellSignedUrlService $urls,
        private readonly ShopifyParentOrderVerifier $orders,
    ) {}

    /**
     * Resolve the offer for $shop from the request's purchase context and return
     * the JSON payload (offer + SIGNED action URLs), or `['offer' => null]` when
     * nothing matches. The caller has already bound $shop as the tenant.
     *
     * IDENTITY LAW: the accept URL this mints charges a saved card, so the customer
     * and parent order inside it NEVER come from the query string. $verifiedCustomerId
     * is the shopper the CALLER's auth proved (the App Proxy's signed
     * logged_in_customer_id, or the session token's `sub`), and the parent order is
     * only signed after Shopify confirms it belongs to that shopper. No verified
     * shopper, or an order that is not theirs → no offer, no impression, no hold.
     *
     * @param  string  $verifiedCustomerId  numeric Shopify customer id, '' when none
     * @return array<string, mixed>
     */
    public function respond(Request $request, Shop $shop, string $verifiedCustomerId): array
    {
        // This responder feeds the PAYPLUS-TOKEN rail (the App-Proxy widget and the
        // thank-you / order-status extensions). Accepting one of these offers
        // charges a vaulted PayPlus token, so a shop with no PayPlus connection
        // cannot complete the purchase the offer invites — UpsellChargeService
        // would answer noMethod and the shopper would meet a dead button.
        //
        // Offering nothing is the honest outcome, and the gate sits BEFORE
        // resolve() on purpose: resolve() records an impression, and a funnel that
        // counts offers that could never convert reports a conversion rate that
        // is not real.
        //
        // The NATIVE post-purchase rail is unaffected — PostPurchaseController
        // calls the resolver itself, because Shopify re-charges the card there and
        // no PayPlus token is involved.
        if (! $shop->hasPayplusConnection()) {
            return ['offer' => null, 'reason' => 'no_payplus_rail'];
        }

        if ($verifiedCustomerId === '') {
            return ['offer' => null, 'reason' => self::REASON_NO_CUSTOMER];
        }

        // BEFORE resolve(): resolving records an impression and may place a
        // fulfillment hold, and neither may happen on an order that is not this
        // shopper's.
        $purchase = $this->orders->verify($shop, (string) $request->query('parent_order', ''), $verifiedCustomerId);
        if ($purchase === null) {
            return ['offer' => null, 'reason' => self::REASON_UNVERIFIED_ORDER];
        }

        $context = $this->buildContext($request, $shop, $purchase);
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

    /**
     * Identity (order + customer + email) from the VERIFIED purchase; only the
     * trigger inputs (products, collections, tags, subtotal) are read from the query,
     * and those decide WHICH offer is shown — never whose card pays for it.
     */
    private function buildContext(Request $request, Shop $shop, VerifiedPurchase $purchase): PurchaseContext
    {
        return new PurchaseContext(
            shopId: (int) $shop->getKey(),
            parentOrderId: $purchase->parentOrderId,
            customerRef: $purchase->customerRef,
            orderSubtotal: (float) $request->query('subtotal', 0),
            purchasedProductGids: $this->csv($request->query('products')),
            purchasedCollectionGids: $this->csv($request->query('collections')),
            purchasedTags: $this->csv($request->query('tags')),
            customerEmail: $purchase->customerEmail,
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
