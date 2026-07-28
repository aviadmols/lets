<?php

namespace App\Domain\Upsell\Http\Controllers;

use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Domain\Upsell\PostPurchase\ChangesetSigner;
use App\Domain\Upsell\PostPurchase\PostPurchaseTokenVerifier;
use App\Domain\Upsell\PurchaseContext;
use App\Domain\Upsell\Rendering\PostPurchasePresenter;
use App\Domain\Upsell\Rendering\UpsellCardPresenter;
use App\Domain\Upsell\UpsellResolver;
use App\Http\Controllers\Controller;
use App\Models\MerchantUpsellAppearance;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The NATIVE post-purchase rail — the interstitial Shopify shows immediately
 * after checkout, before the thank-you page.
 *
 * Why it exists next to the thank-you widget: that widget charges a saved
 * PAYPLUS token, so it cannot serve a store that sells through Shopify
 * Payments. Here Shopify itself re-charges the payment method the shopper
 * already used, via a CHANGESET we sign. One offer catalogue (the Flow Builder),
 * two ways to collect the money.
 *
 * Trust model — the extension runs in a sandboxed worker on an origin we do not
 * control, so it is never believed:
 *   - the SHOP comes from Shopify's signed token, not from any parameter;
 *   - the PURCHASE (line items, total, customer) comes from that same token;
 *   - the OFFER is re-resolved server-side and its price re-computed here, so a
 *     tampered client cannot get a signed instruction to sell at its own price.
 *
 * Two endpoints, because Shopify's extension lifecycle has two moments:
 *   POST /post-purchase/offer → "is there anything to show?" (ShouldRender)
 *   POST /post-purchase/sign  → "they said yes; give me the signed changeset"
 */
final class PostPurchaseController extends Controller
{
    // === CONSTANTS ===
    /** Money answers come from the offer; this is only the display fallback. */
    private const DEFAULT_CURRENCY = 'ILS';

    /** The presenter's platform discriminator for this surface. */
    public const PLATFORM = 'shopify_post_purchase';

    public function __construct(
        private readonly PostPurchaseTokenVerifier $verifier,
        private readonly UpsellResolver $resolver,
        private readonly ChangesetSigner $signer,
        private readonly UpsellCardPresenter $card,
        private readonly PostPurchasePresenter $presenter,
    ) {}

    /** POST /post-purchase/offer — resolve the offer for the just-completed checkout. */
    public function offer(Request $request): JsonResponse
    {
        $verified = $this->verified($request);
        if ($verified === null) {
            return response()->json(['offer' => null, 'reason' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        [$shop, $claims] = $verified;

        return Tenant::run($shop, function () use ($shop, $claims): JsonResponse {
            $resolution = $this->resolver->resolve($this->contextFrom($shop, $claims));
            if ($resolution === null) {
                return response()->json(['offer' => null]);
            }

            $offer = $resolution->offer;
            $variantId = $this->variantNumericId($offer);
            if ($variantId === null) {
                // An offer with no variant cannot be added to an order — showing it
                // would strand the shopper on an accept that can never apply.
                return response()->json(['offer' => null, 'reason' => 'offer_has_no_variant']);
            }

            UpsellOfferEvent::record([
                'shop_id' => (int) $shop->getKey(),
                'flow_id' => $resolution->flow->getKey(),
                'offer_id' => $offer->getKey(),
                'event_type' => OfferEventType::IMPRESSION,
                'parent_order_id' => $this->verifier->referenceId($claims),
                'customer_ref' => $this->customerRef($claims),
                'currency' => (string) ($offer->currency ?: self::DEFAULT_CURRENCY),
            ]);

            // The merchant's own copy and element choices, translated to what the
            // post-purchase component set can express. Without this the extension
            // renders hardcoded English and the headline, sub-copy and button
            // labels the merchant actually configured are discarded in transit.
            $appearance = MerchantUpsellAppearance::current();
            $presentation = $this->presenter->present(
                $this->card->forOffer($offer, $appearance, self::PLATFORM),
                $appearance,
            );

            return response()->json(['offer' => [
                'flow_id' => (int) $resolution->flow->getKey(),
                'offer_id' => (int) $offer->getKey(),
                'title' => (string) ($offer->offer_title ?? __('upsell.offer_default_title')),
                'description' => (string) ($offer->offer_description ?? ''),
                'image_url' => $offer->resolveProduct()?->image_url,
                'variant_id' => $variantId,
                // Server-computed money truth — the client never sends a price.
                'price' => $resolution->discountedPrice(),
                'base_price' => round((float) $offer->base_price, 2),
                'currency' => (string) ($offer->currency ?: self::DEFAULT_CURRENCY),
                'presentation' => $presentation,
            ]]);
        });
    }

    /**
     * POST /post-purchase/sign — the shopper accepted; return the signed changeset
     * the extension hands to Shopify's applyChangeset.
     */
    public function sign(Request $request): JsonResponse
    {
        $verified = $this->verified($request);
        if ($verified === null) {
            return response()->json(['ok' => false, 'reason' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        [$shop, $claims, $appKey] = $verified;
        $referenceId = $this->verifier->referenceId($claims);
        if ($referenceId === '') {
            return response()->json(['ok' => false, 'reason' => 'no_reference'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return Tenant::run($shop, function () use ($request, $shop, $claims, $appKey, $referenceId): JsonResponse {
            // Re-resolve the offer from ITS OWN id, tenant-scoped: the client may
            // say WHICH offer it accepted, never WHAT that offer costs.
            $offer = UpsellFlowOffer::query()->find((int) $request->input('offer_id'));
            if ($offer === null) {
                return response()->json(['ok' => false, 'reason' => 'unknown_offer'], Response::HTTP_NOT_FOUND);
            }

            $variantId = $this->variantNumericId($offer);
            if ($variantId === null) {
                return response()->json(['ok' => false, 'reason' => 'offer_has_no_variant'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // The discount is expressed to Shopify as money off the variant's own
            // price, so the shopper is charged exactly the offer price we computed.
            $base = round((float) $offer->base_price, 2);
            $price = $offer->discountedPrice();
            $unitDiscount = max(0, round($base - $price, 2));

            $changeset = $this->signer->sign(
                appKey: $appKey,
                referenceId: $referenceId,
                variantId: $variantId,
                quantity: 1,
                unitDiscount: $unitDiscount,
                discountTitle: (string) ($offer->offer_title ?? ''),
            );

            UpsellOfferEvent::record([
                'shop_id' => (int) $shop->getKey(),
                'flow_id' => $offer->flow_id,
                'offer_id' => $offer->getKey(),
                'event_type' => OfferEventType::ACCEPTED,
                'parent_order_id' => $referenceId,
                'customer_ref' => $this->customerRef($claims),
                'currency' => (string) ($offer->currency ?: self::DEFAULT_CURRENCY),
            ]);

            // No payment_ledger row: this rail takes no money of its own. SHOPIFY
            // re-charges the original payment method when it applies the changeset,
            // and its own order webhooks are what confirm the sale.
            return response()->json([
                'ok' => true,
                'changeset' => $changeset,
                'price' => $price,
                'currency' => (string) ($offer->currency ?: self::DEFAULT_CURRENCY),
            ]);
        });
    }

    /**
     * Verify the Shopify token and resolve the live shop it names.
     *
     * @return array{0: Shop, 1: array<string, mixed>, 2: string}|null
     */
    private function verified(Request $request): ?array
    {
        $token = (string) ($request->input('token') ?: $request->bearerToken());
        if ($token === '') {
            return null;
        }

        $verified = $this->verifier->verify($token);
        if ($verified === null) {
            return null;
        }

        $domain = $this->verifier->shopDomain($verified['claims']);
        if ($domain === '') {
            return null;
        }

        $shop = Shop::query()->where('shopify_domain', $domain)->first();
        if ($shop === null || ! $shop->isLive()) {
            return null;
        }

        return [$shop, $verified['claims'], $verified['app_key']];
    }

    /**
     * The purchase the offer is evaluated against, built ONLY from verified
     * claims — the same trigger inputs the thank-you rail uses, so a merchant's
     * flows behave identically on both.
     *
     * @param  array<string, mixed>  $claims
     */
    private function contextFrom(Shop $shop, array $claims): PurchaseContext
    {
        $lineItems = (array) (data_get($claims, 'input_data.initialPurchase.lineItems') ?? []);

        $productGids = [];
        foreach ($lineItems as $line) {
            $productId = data_get($line, 'product.id') ?? data_get($line, 'productId');
            if ($productId !== null && $productId !== '') {
                $productGids[] = 'gid://shopify/Product/'.$productId;
                $productGids[] = (string) $productId;
            }
        }

        $subtotal = (float) (data_get($claims, 'input_data.initialPurchase.totalPriceSet.presentmentMoney.amount')
            ?? data_get($claims, 'input_data.initialPurchase.totalPrice')
            ?? 0);

        return new PurchaseContext(
            shopId: (int) $shop->getKey(),
            parentOrderId: $this->verifier->referenceId($claims),
            customerRef: $this->customerRef($claims),
            orderSubtotal: round($subtotal, 2),
            purchasedProductGids: array_values(array_unique($productGids)),
            currency: (string) (data_get($claims, 'input_data.initialPurchase.totalPriceSet.presentmentMoney.currencyCode') ?: self::DEFAULT_CURRENCY),
            shopifyCustomerId: $this->customerRef($claims) ?: null,
        );
    }

    /** @param array<string, mixed> $claims */
    private function customerRef(array $claims): string
    {
        return (string) (data_get($claims, 'input_data.initialPurchase.customerId') ?? '');
    }

    /**
     * The NUMERIC Shopify variant id the changeset must carry. An offer stores a
     * variant gid when the merchant picked one; otherwise there is nothing
     * addable and the caller refuses (rather than guessing a variant).
     */
    private function variantNumericId(UpsellFlowOffer $offer): ?int
    {
        $gid = (string) ($offer->offer_variant_gid ?? '');
        if ($gid !== '' && preg_match('/(\d+)$/', $gid, $m) === 1) {
            return (int) $m[1];
        }

        // Fall back to the catalog product's first variant — the merchant picked a
        // product, so the sellable variant is unambiguous for a single-variant one.
        $variant = $offer->resolveProduct()?->primaryVariant();
        $external = (string) ($variant?->external_variant_id ?? '');

        return preg_match('/(\d+)$/', $external, $m) === 1 ? (int) $m[1] : null;
    }
}
