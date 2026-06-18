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
 * The DIRECT-fetch extension seam: GET /upsell/offer behind SessionTokenAuth.
 *
 * The checkout / customer-account UI extensions (purchase.thank-you.block.render,
 * customer-account.order-status.block.render) run in a sandboxed worker with no
 * storefront origin/session, so they cannot use the relative App-Proxy path. They
 * direct-fetch this absolute endpoint with a session-token (JWT) bearer. This test
 * proves the endpoint is:
 *   - session-token authed (a valid App Bridge JWT is served; missing/forged/
 *     foreign tokens are rejected 401 — fail closed);
 *   - tenant-bound by the VERIFIED `dest` shop in the JWT (shop A's token can never
 *     return shop B's offer, even though the client sends NO shop id);
 *   - money-safe (the returned price is the SERVER-computed discounted price, never
 *     a client input) and returns { offer: null } when nothing matches;
 *   - SAME JSON shape as the App-Proxy offer endpoint (offer + absolute signed
 *     accept_api_url), since both delegate to OfferResponder.
 */
final class SessionTokenOfferEndpointTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const API_KEY = 'upsell_offer_api_key';
    private const API_SECRET = 'upsell_offer_api_secret';
    private const ENDPOINT = '/upsell/offer';

    protected function setUp(): void
    {
        parent::setUp();
        // The session token is an HS256 JWT signed with the app secret; aud is the
        // app key. Pin both so SessionTokenAuth/SessionTokenVerifier can verify.
        config()->set('shopify.api_key', self::API_KEY);
        config()->set('shopify.api_secret', self::API_SECRET);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_valid_session_token_returns_the_shops_offer_with_server_computed_price(): void
    {
        $shop = $this->makeInstalledShop('alpha.myshopify.com');
        $offer = $this->makeMatchingFlowWithDiscount($shop, base: 100.0, percent: 10);

        $response = $this->getOfferWithToken($shop->shopify_domain, [
            'parent_order' => 'P-100',
            'customer' => 'cust-1',
            'subtotal' => '250',
            'products' => 'gid://shopify/Product/1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('offer.offer_id', $offer->id);
        // Money law: 100 - 10% = 90, computed server-side (not from the request).
        $this->assertSame(90.0, (float) $response->json('offer.price'));
        $this->assertSame(100.0, (float) $response->json('offer.base_price'));

        // The signed action URLs are present + ABSOLUTE so the extension can POST
        // accept cross-origin with no shop id and no client amount.
        $this->assertNotEmpty($response->json('accept_api_url'));
        $this->assertStringStartsWith('http', (string) $response->json('accept_api_url'));
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
        $shop = $this->makeInstalledShop('alpha.myshopify.com');
        // A flow that only matches Product/999 — the purchase is Product/1.
        $this->makeMatchingFlowWithDiscount($shop, base: 50.0, percent: 0, productGid: 'gid://shopify/Product/999');

        $response = $this->getOfferWithToken($shop->shopify_domain, [
            'parent_order' => 'P-1',
            'customer' => 'c',
            'subtotal' => '10',
            'products' => 'gid://shopify/Product/1',
        ]);

        $response->assertOk();
        $response->assertExactJson(['offer' => null]);
    }

    public function test_missing_token_is_rejected_401(): void
    {
        $this->makeInstalledShop('alpha.myshopify.com');

        $this->getJson(self::ENDPOINT.'?products=gid://shopify/Product/1')
            ->assertStatus(401)
            ->assertHeader('X-Shopify-API-Request-Failure-Reauthorize', '1');
    }

    public function test_token_signed_with_wrong_secret_is_rejected_401(): void
    {
        $shop = $this->makeInstalledShop('alpha.myshopify.com');
        $this->makeMatchingFlowWithDiscount($shop, base: 100.0, percent: 0);

        $jwt = $this->makeJwt($shop->shopify_domain, self::API_KEY, 'WRONG_SECRET');

        $this->withToken($jwt)->getJson(self::ENDPOINT.'?products=gid://shopify/Product/1')
            ->assertStatus(401);
    }

    public function test_token_for_unknown_shop_is_rejected_401(): void
    {
        // No Shop row for the dest shop ⇒ reject (never resolve an offer).
        $jwt = $this->makeJwt('ghost.myshopify.com', self::API_KEY, self::API_SECRET);

        $this->withToken($jwt)->getJson(self::ENDPOINT.'?products=gid://shopify/Product/1')
            ->assertStatus(401);
    }

    public function test_session_token_for_shop_a_never_returns_shop_b_offer(): void
    {
        $shopA = $this->makeInstalledShop('a.myshopify.com');
        $shopB = $this->makeInstalledShop('b.myshopify.com');

        // Only Shop B has a matching flow.
        Tenant::run($shopB, fn () => $this->makeMatchingFlowWithDiscount($shopB, base: 80.0, percent: 0));
        Tenant::clear();

        // Shop A's token requests the SAME purchased product — gets nothing.
        $response = $this->getOfferWithToken($shopA->shopify_domain, [
            'parent_order' => 'P-1',
            'customer' => 'c',
            'subtotal' => '500',
            'products' => 'gid://shopify/Product/1',
        ]);

        $response->assertOk();
        $response->assertExactJson(['offer' => null]);
    }

    public function test_preflight_options_returns_cors_headers_without_auth(): void
    {
        // The browser sends an unauthenticated OPTIONS preflight first; it must be
        // answered with CORS headers (and never reach the auth gate).
        $response = $this->call('OPTIONS', self::ENDPOINT);

        $response->assertNoContent(204);
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringContainsString('Authorization', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_successful_response_carries_cors_header(): void
    {
        $shop = $this->makeInstalledShop('alpha.myshopify.com');
        $this->makeMatchingFlowWithDiscount($shop, base: 100.0, percent: 0);

        $this->getOfferWithToken($shop->shopify_domain, [
            'products' => 'gid://shopify/Product/1',
        ])->assertHeader('Access-Control-Allow-Origin', '*');
    }

    // === Helpers ===

    /**
     * GET the offer endpoint with a valid session-token bearer for $shopDomain.
     *
     * @param  array<string, string>  $params
     */
    private function getOfferWithToken(string $shopDomain, array $params): \Illuminate\Testing\TestResponse
    {
        $jwt = $this->makeJwt($shopDomain, self::API_KEY, self::API_SECRET);

        return $this->withToken($jwt)->getJson(self::ENDPOINT.'?'.http_build_query($params));
    }

    private function makeJwt(string $shop, string $aud, string $secret, ?int $exp = null): string
    {
        $now = time();
        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->b64(json_encode([
            'iss' => 'https://'.$shop.'/admin',
            'dest' => 'https://'.$shop.'/admin',
            'aud' => $aud,
            'sub' => '123',
            'exp' => $exp ?? ($now + 60),
            'nbf' => $now - 5,
            'iat' => $now,
        ]));
        $signature = $this->b64(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function makeInstalledShop(string $domain): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
        // SessionTokenAuth requires a live shop; capture an install so isLive() holds.
        $shop->captureShopifyInstall('shpat_token', 'read_orders');

        return $shop->fresh();
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
