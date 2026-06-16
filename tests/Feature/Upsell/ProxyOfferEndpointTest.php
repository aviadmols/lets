<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The extension → backend seam: GET /proxy/upsell/offer behind VerifyShopifyAppProxy.
 * Proves the offer endpoint is:
 *   - App-Proxy-signature authed (a correctly-signed request is served; an
 *     unsigned/forged one is rejected 401 — fail closed);
 *   - tenant-bound by the VERIFIED `shop` param (shop A's signed request can never
 *     return shop B's offer);
 *   - money-safe (the returned price is the SERVER-computed discounted price, never
 *     a client input) and returns { offer: null } when nothing matches.
 */
final class ProxyOfferEndpointTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SECRET = 'test_app_proxy_secret';
    private const ENDPOINT = '/proxy/upsell/offer';

    protected function setUp(): void
    {
        parent::setUp();
        // The proxy signature is signed with the app secret (webhook_secret falls
        // back to api_secret in config). Pin both so the middleware can verify.
        config()->set('shopify.webhook_secret', self::SECRET);
        config()->set('shopify.api_secret', self::SECRET);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_signed_request_returns_the_shops_offer_with_server_computed_price(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $offer = $this->makeMatchingFlowWithDiscount($shop, base: 100.0, percent: 10);

        $response = $this->getSignedOffer($shop->shopify_domain, [
            'parent_order' => 'P-100',
            'customer' => 'cust-1',
            'subtotal' => '250',
            'products' => 'gid://shopify/Product/1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('offer.offer_id', $offer->id);
        // Money law: 100 - 10% = 90, computed server-side (not from the request).
        // (JSON serialises 90.0 → 90, so compare the numeric value.)
        $this->assertSame(90.0, (float) $response->json('offer.price'));
        $this->assertSame(100.0, (float) $response->json('offer.base_price'));

        // The signed action URLs are present so the extension can act with no
        // shop id and no client amount.
        $this->assertNotEmpty($response->json('accept_api_url'));
        $this->assertNotEmpty($response->json('accept_url'));
        $this->assertNotEmpty($response->json('decline_url'));

        // Resolving recorded exactly one impression (the funnel anchor).
        $this->assertSame(1, UpsellOfferEvent::withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->where('event_type', OfferEventType::IMPRESSION->value)
            ->count());
    }

    public function test_returns_null_offer_when_nothing_matches(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        // A flow that only matches Product/999 — the purchase is Product/1.
        $this->makeMatchingFlowWithDiscount($shop, base: 50.0, percent: 0, productGid: 'gid://shopify/Product/999');

        $response = $this->getSignedOffer($shop->shopify_domain, [
            'parent_order' => 'P-1',
            'customer' => 'c',
            'subtotal' => '10',
            'products' => 'gid://shopify/Product/1',
        ]);

        $response->assertOk();
        $response->assertExactJson(['offer' => null]);
    }

    public function test_forged_signature_is_rejected_401_and_serves_nothing(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $this->makeMatchingFlowWithDiscount($shop, base: 100.0, percent: 0);

        // A syntactically-present but wrong signature must fail closed.
        $query = http_build_query([
            'shop' => $shop->shopify_domain,
            'products' => 'gid://shopify/Product/1',
            'signature' => 'deadbeef-not-a-real-signature',
        ]);

        $this->getJson(self::ENDPOINT.'?'.$query)->assertStatus(401);
    }

    public function test_unsigned_request_is_rejected_401(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');

        $query = http_build_query([
            'shop' => $shop->shopify_domain,
            'products' => 'gid://shopify/Product/1',
        ]);

        $this->getJson(self::ENDPOINT.'?'.$query)->assertStatus(401);
    }

    public function test_signed_request_for_shop_a_never_returns_shop_b_offer(): void
    {
        $shopA = $this->makeShop('a.myshopify.com');
        $shopB = $this->makeShop('b.myshopify.com');

        // Only Shop B has a matching flow.
        Tenant::run($shopB, fn () => $this->makeMatchingFlowWithDiscount($shopB, base: 80.0, percent: 0));
        Tenant::clear();

        // Shop A signs a request for the SAME purchased product — gets nothing.
        $response = $this->getSignedOffer($shopA->shopify_domain, [
            'parent_order' => 'P-1',
            'customer' => 'c',
            'subtotal' => '500',
            'products' => 'gid://shopify/Product/1',
        ]);

        $response->assertOk();
        $response->assertExactJson(['offer' => null]);
    }

    // === Helpers ===

    /**
     * Build a correctly-signed App Proxy GET request to the offer endpoint. Mirrors
     * ShopifyDomain::verifyProxySignature: sorted `key=value` pairs concatenated
     * with NO separator, HMAC-SHA256 hex with the app secret, in the `signature`
     * query param.
     *
     * @param  array<string, string>  $params
     */
    private function getSignedOffer(string $shopDomain, array $params): \Illuminate\Testing\TestResponse
    {
        $params['shop'] = $shopDomain;
        ksort($params);

        $message = '';
        foreach ($params as $key => $value) {
            $message .= $key.'='.$value;
        }
        $params['signature'] = hash_hmac('sha256', $message, self::SECRET);

        return $this->getJson(self::ENDPOINT.'?'.http_build_query($params));
    }

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    private function makeMatchingFlowWithDiscount(
        Shop $shop,
        float $base,
        int $percent,
        string $productGid = 'gid://shopify/Product/1',
    ): UpsellFlowOffer {
        return Tenant::run($shop, function () use ($shop, $base, $percent, $productGid): UpsellFlowOffer {
            $flow = new UpsellFlow(['name' => 'Flow', 'priority' => 5]);
            $flow->shop_id = $shop->id;
            $flow->forceFill(['status' => UpsellFlowStatus::ACTIVE->value])->save();

            UpsellFlowTrigger::create([
                'flow_id' => $flow->id,
                'match_type' => UpsellFlowTrigger::MATCH_SPECIFIC_PRODUCT,
                'shopify_product_gid' => $productGid,
            ]);

            return UpsellFlowOffer::create([
                'flow_id' => $flow->id,
                'offer_product_gid' => 'gid://shopify/Product/77',
                'offer_variant_gid' => 'gid://shopify/ProductVariant/770',
                'offer_title' => 'Add-on',
                'base_price' => $base,
                'discount_type' => $percent > 0 ? UpsellFlowOffer::DISCOUNT_PERCENT : UpsellFlowOffer::DISCOUNT_NONE,
                'discount_value' => $percent,
                'position' => 0,
            ]);
        });
    }
}
