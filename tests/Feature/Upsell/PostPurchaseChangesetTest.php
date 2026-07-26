<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The NATIVE post-purchase rail (Shopify Payments): Shopify re-charges the
 * checkout's own payment method from a CHANGESET WE SIGN. The signature is the
 * authority, so these tests are about what may and may not get signed:
 *
 *   1. only a token Shopify signed with the app secret is served at all;
 *   2. the shop comes from that token — never from a parameter;
 *   3. the price is re-computed server-side, so a client that asks for its own
 *      price still gets the merchant's;
 *   4. the changeset carries the offer's variant and is signed with the SAME
 *      app's secret, so Shopify will accept it.
 */
final class PostPurchaseChangesetTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const API_KEY = 'pp_api_key';
    private const API_SECRET = 'pp_api_secret';
    private const REFERENCE = 'ref-9001';
    private const OFFER_ENDPOINT = '/post-purchase/offer';
    private const SIGN_ENDPOINT = '/post-purchase/sign';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('shopify.api_key', self::API_KEY);
        config()->set('shopify.api_secret', self::API_SECRET);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_an_unsigned_token_is_refused(): void
    {
        $this->shopWithOffer();

        $this->postJson(self::OFFER_ENDPOINT, ['token' => 'not.a.jwt'])
            ->assertUnauthorized()
            ->assertJson(['offer' => null]);
    }

    public function test_a_token_signed_with_the_wrong_secret_is_refused(): void
    {
        $shop = $this->shopWithOffer();

        $this->postJson(self::OFFER_ENDPOINT, [
            'token' => $this->token($shop->shopify_domain, secret: 'someone-elses-secret'),
        ])->assertUnauthorized();
    }

    public function test_the_offer_is_resolved_from_the_shop_inside_the_token(): void
    {
        $shop = $this->shopWithOffer();

        $response = $this->postJson(self::OFFER_ENDPOINT, ['token' => $this->token($shop->shopify_domain)]);

        $response->assertOk();
        // 100 base with 25% off → the server's price, not anything the client said.
        $response->assertJsonPath('offer.price', 75);
        $response->assertJsonPath('offer.variant_id', 4242);
    }

    public function test_accepting_returns_a_changeset_signed_with_the_apps_secret(): void
    {
        $shop = $this->shopWithOffer();
        $offer = Tenant::run($shop, fn () => UpsellFlowOffer::query()->firstOrFail());

        $response = $this->postJson(self::SIGN_ENDPOINT, [
            'token' => $this->token($shop->shopify_domain),
            'offer_id' => $offer->getKey(),
            // A tampered client trying to set its own price — must be ignored.
            'price' => 1,
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $response->assertJsonPath('price', 75);

        $changeset = $response->json('changeset');
        [$header, $payload, $signature] = explode('.', $changeset);

        // Signed with THIS app's secret — Shopify verifies exactly this.
        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $header.'.'.$payload, self::API_SECRET, true)
        ), '+/', '-_'), '=');
        $this->assertSame($expected, $signature);

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame(self::API_KEY, $claims['iss']);
        $this->assertSame(self::REFERENCE, $claims['sub']);

        $change = $claims['changes'][0];
        $this->assertSame('add_variant', $change['type']);
        $this->assertSame(4242, $change['variantId']);
        // 100 → 75 means 25 off the variant's own price (JSON may carry it as int).
        $this->assertEqualsWithDelta(25.0, (float) $change['discount']['value'], 0.001);
        $this->assertSame('fixed_amount', $change['discount']['valueType']);
    }

    public function test_an_unknown_offer_id_signs_nothing(): void
    {
        $shop = $this->shopWithOffer();

        $this->postJson(self::SIGN_ENDPOINT, [
            'token' => $this->token($shop->shopify_domain),
            'offer_id' => 999999,
        ])->assertNotFound()->assertJson(['ok' => false]);
    }

    // === Helpers ===

    private function shopWithOffer(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'post-purchase.myshopify.com',
            'name' => 'Post Purchase',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->forceFill(['shopify_access_token' => 'tok'])->save();
        $shop = $shop->fresh();

        Tenant::run($shop, function () use ($shop): void {
            $flow = new UpsellFlow(['name' => 'PP flow', 'priority' => 1]);
            $flow->shop_id = (int) $shop->getKey();
            $flow->forceFill(['status' => UpsellFlowStatus::ACTIVE->value])->save();

            // An any-product trigger, so any purchase in the token matches.
            UpsellFlowTrigger::create([
                'flow_id' => (int) $flow->getKey(),
                'match_type' => UpsellFlowTrigger::MATCH_ANY_PRODUCT,
            ]);

            UpsellFlowOffer::create([
                'flow_id' => (int) $flow->getKey(),
                'offer_title' => 'Add a grinder',
                'offer_product_gid' => 'gid://shopify/Product/777',
                'offer_variant_gid' => 'gid://shopify/ProductVariant/4242',
                'base_price' => 100,
                'discount_type' => UpsellFlowOffer::DISCOUNT_PERCENT,
                'discount_value' => 25,
                'currency' => 'ILS',
                'headline' => 'Add this',
                'accept_cta' => 'Add to my order',
                'position' => 0,
            ]);
        });

        return $shop;
    }

    /** A REAL post-purchase token in Shopify's shape, signed like Shopify signs it. */
    private function token(string $domain, ?string $secret = null): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(
            base64_encode((string) json_encode($part)),
            '+/',
            '-_'
        ), '=');

        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode([
            'iss' => 'shopify',
            'iat' => time(),
            'exp' => time() + 600,
            'input_data' => [
                'shop' => ['id' => 1, 'domain' => $domain],
                'initialPurchase' => [
                    'referenceId' => self::REFERENCE,
                    'customerId' => '5150',
                    'lineItems' => [['product' => ['id' => 111]]],
                    'totalPriceSet' => ['presentmentMoney' => ['amount' => '250.00', 'currencyCode' => 'ILS']],
                ],
            ],
        ]);
        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $header.'.'.$payload, $secret ?? self::API_SECRET, true)
        ), '+/', '-_'), '=');

        return $header.'.'.$payload.'.'.$signature;
    }
}
