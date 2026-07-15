<?php

namespace Tests\Feature\WooCommerce;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * W11 P4 — the WooCommerce post-purchase upsell. /offer resolves the eligible offer
 * (records the impression); /accept REUSES UpsellChargeService::accept VERBATIM (charges
 * the saved token, consent-gated, idempotent) and records a linked PAID WC child order.
 * The shop is the HMAC-verified shop; a double-click charges ONCE; no consent → fail closed.
 */
final class WooCommerceUpsellFlowTest extends TestCase
{
    use RefreshDatabase;

    private const OFFER = '/api/woocommerce/upsell/offer';
    private const ACCEPT = '/api/woocommerce/upsell/accept';

    public int $payplusCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payplusCalls = 0;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private WooCommerceUpsellFlowTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $n = ++$this->test->payplusCalls;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$n]],
                ]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_offer_returns_the_eligible_offer_for_the_order(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('up-offer.example.com');
        Tenant::run($shop, fn () => $this->makeFlow($shop, 'gid://shopify/Product/1', 50.0));

        $query = http_build_query(['parent_order' => 'WC-1', 'customer' => 'cust-1', 'products' => 'gid://shopify/Product/1', 'subtotal' => '120']);
        $response = $this->signedGet($key, $secret, self::OFFER, $query);

        $response->assertOk();
        $this->assertNotNull($response->json('offer'));
        $this->assertSame('Add-on', $response->json('offer.title'));
        $this->assertEqualsWithDelta(50.0, (float) $response->json('offer.price'), 0.001);
    }

    public function test_accept_charges_once_and_records_a_paid_wc_child_order(): void
    {
        Http::fake(['*/wp-json/wc/v3/orders' => Http::response(['id' => 7700], 201)]);
        [$shop, $key, $secret] = $this->connectedShop('up-accept.example.com');

        [$flow, $offer] = Tenant::run($shop, function () use ($shop): array {
            $flow = $this->makeFlow($shop, 'gid://shopify/Product/1', 50.0);
            $this->makeConsentAndToken($shop, 'cust-1');

            return [$flow, $flow->offers()->first()];
        });

        $body = ['flow_id' => $flow->id, 'offer_id' => $offer->id, 'parent_order' => 'WC-1', 'customer' => 'cust-1', 'email' => 'x@y.com'];

        $first = $this->signedPost($key, $secret, self::ACCEPT, $body);
        $first->assertOk()->assertJsonPath('charged', true)->assertJsonPath('child_order_id', '7700');

        // Double-click: idempotent — the engine charges ONCE; no second PayPlus call.
        $this->signedPost($key, $secret, self::ACCEPT, $body)->assertOk();

        $this->assertSame(1, $this->payplusCalls, 'Exactly one charge across two accepts.');
        $succeeded = Tenant::run($shop, fn (): int => PaymentLedger::query()
            ->where('status', LedgerStatus::SUCCEEDED->value)->count());
        $this->assertSame(1, $succeeded);

        // A linked PAID WC child order was created on the (first) charge.
        Http::assertSent(function (HttpRequest $req) {
            $b = $req->data();
            return str_contains($req->url(), '/wp-json/wc/v3/orders')
                && ($b['status'] ?? null) === 'completed'
                && ($b['set_paid'] ?? null) === true
                && $this->meta($b, 'lets_order_role') === 'upsell_child'
                && $this->meta($b, 'lets_parent_order_id') === 'WC-1';
        });
    }

    /**
     * The shopper's explicit "Add to my order" click IS the authorization: accept() RECORDS the
     * upsell consent (before charging) rather than requiring a row nothing in production ever
     * wrote. The remaining fail-closed guarantee is the SAVED CARD — see the next test.
     */
    public function test_accept_records_upsell_consent_from_the_click(): void
    {
        Http::fake();
        [$shop, $key, $secret] = $this->connectedShop('up-consent.example.com');

        [$flow, $offer] = Tenant::run($shop, function () use ($shop): array {
            $flow = $this->makeFlow($shop, 'gid://shopify/Product/1', 50.0);
            // A vaulted card, and NO pre-existing consent row.
            InstallmentPaymentMethod::create([
                'shopify_customer_id' => 'cust-1', 'payplus_card_token_uid' => 'tok',
                'card_last_four' => '4242', 'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            return [$flow, $flow->offers()->first()];
        });

        $this->signedPost($key, $secret, self::ACCEPT, [
            'flow_id' => $flow->id, 'offer_id' => $offer->id, 'parent_order' => 'WC-1', 'customer' => 'cust-1',
        ])->assertOk()->assertJsonPath('result', 'charged');

        Tenant::run($shop, function (): void {
            $consent = CustomerConsent::where('consent_context', CustomerConsent::CONTEXT_UPSELL)->sole();
            $this->assertSame('cust-1', $consent->shopify_customer_id);
        });
    }

    /** "No thanks" records the DECLINED funnel event (parity with Shopify) and never charges. */
    public function test_decline_records_the_declined_event_and_never_charges(): void
    {
        Http::fake();
        [$shop, $key, $secret] = $this->connectedShop('up-decline.example.com');

        [$flow, $offer] = Tenant::run($shop, function () use ($shop): array {
            $flow = $this->makeFlow($shop, 'gid://shopify/Product/1', 50.0);

            return [$flow, $flow->offers()->first()];
        });

        $this->signedPost($key, $secret, '/api/woocommerce/upsell/decline', [
            'flow_id' => $flow->id, 'offer_id' => $offer->id, 'parent_order' => 'WC-1', 'customer' => 'cust-1',
        ])->assertOk();

        Tenant::run($shop, function () use ($shop, $flow): void {
            $this->assertDatabaseHas('upsell_offer_events', [
                'shop_id' => $shop->id, 'flow_id' => $flow->id, 'event_type' => 'declined',
            ]);
            $this->assertSame(0, PaymentLedger::where('shop_id', $shop->id)->count());
        });
    }

    /** No vaulted card = no charge, no ledger, and no manufactured consent. */
    public function test_accept_without_a_saved_card_fails_closed_with_no_charge(): void
    {
        Http::fake();
        [$shop, $key, $secret] = $this->connectedShop('up-nocard.example.com');

        [$flow, $offer] = Tenant::run($shop, function () use ($shop): array {
            $flow = $this->makeFlow($shop, 'gid://shopify/Product/1', 50.0);

            return [$flow, $flow->offers()->first()];
        });

        $this->signedPost($key, $secret, self::ACCEPT, [
            'flow_id' => $flow->id, 'offer_id' => $offer->id, 'parent_order' => 'WC-1', 'customer' => 'cust-1',
        ])->assertStatus(422)->assertJsonPath('result', 'no_payment_method');

        $this->assertSame(0, $this->payplusCalls);
        Http::assertNothingSent(); // no child order without a charge
    }

    public function test_an_unsigned_accept_is_rejected_401(): void
    {
        $this->postJson(self::ACCEPT, ['flow_id' => 1, 'offer_id' => 1])->assertStatus(401);
    }

    public function test_a_shop_can_never_accept_against_another_shops_flow(): void
    {
        Http::fake();
        [$shopA] = $this->connectedShop('up-iso-a.example.com');
        $flowA = Tenant::run($shopA, fn () => $this->makeFlow($shopA, 'gid://shopify/Product/1', 50.0));
        $offerA = Tenant::run($shopA, fn () => $flowA->offers()->first());

        [, $keyB, $secretB] = $this->connectedShop('up-iso-b.example.com');

        // Shop B signs an accept naming shop A's flow/offer → tenant-scoped 404.
        $this->signedPost($keyB, $secretB, self::ACCEPT, [
            'flow_id' => $flowA->id, 'offer_id' => $offerA->id, 'parent_order' => 'X', 'customer' => 'c',
        ])->assertStatus(404);

        $this->assertSame(0, $this->payplusCalls);
    }

    // === Helpers ===

    /** @return array{0:Shop,1:string,2:string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];
        $shop->woocommerce_credentials = array_merge($shop->woocommerce_credentials ?: [], [
            'base_url' => 'https://'.$domain, 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't'];
        $shop->save();

        [$key, $secret] = $this->keys($result['connection_token']);

        return [$shop->fresh(), $key, $secret];
    }

    private function makeFlow(Shop $shop, string $productGid, float $base): UpsellFlow
    {
        $flow = new UpsellFlow(['name' => 'Flow', 'priority' => 5]);
        $flow->shop_id = $shop->id;
        $flow->forceFill(['status' => UpsellFlowStatus::ACTIVE->value])->save();

        UpsellFlowTrigger::create([
            'flow_id' => $flow->id,
            'match_type' => UpsellFlowTrigger::MATCH_SPECIFIC_PRODUCT,
            'shopify_product_gid' => $productGid,
        ]);
        UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/77',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/770',
            'offer_title' => 'Add-on',
            'base_price' => $base,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'position' => 0,
        ]);

        return $flow->fresh();
    }

    private function makeConsentAndToken(Shop $shop, string $customerRef): void
    {
        InstallmentPaymentMethod::create([
            'shopify_customer_id' => $customerRef, 'payplus_card_token_uid' => 'tok-1',
            'payplus_customer_uid' => 'cust-uid-1', 'card_last_four' => '4242',
            'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
        ]);
        CustomerConsent::create([
            'shopify_customer_id' => $customerRef,
            'consent_context' => CustomerConsent::CONTEXT_UPSELL,
            'accepted_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $body */
    private function signedPost(string $apiKey, string $apiSecret, string $path, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.$path.$json, $apiSecret, true));

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey, 'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }

    private function signedGet(string $apiKey, string $apiSecret, string $path, string $query): TestResponse
    {
        $ts = (string) time();
        // The middleware signs path + raw body (empty for GET) — NOT the query string.
        $sig = base64_encode(hash_hmac('sha256', $ts.'GET'.$path.'', $apiSecret, true));

        return $this->call('GET', $path.'?'.$query, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey, 'HTTP_X_LETS_TIMESTAMP' => $ts, 'HTTP_X_LETS_SIGNATURE' => $sig,
        ]);
    }

    /** @return array{0:string,1:string} */
    private function keys(string $token): array
    {
        $json = (string) base64_decode(strtr($token, '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [(string) $data['k'], (string) $data['s']];
    }

    private function meta(array $body, string $key): mixed
    {
        foreach ((array) ($body['meta_data'] ?? []) as $m) {
            if (($m['key'] ?? null) === $key) {
                return $m['value'] ?? null;
            }
        }

        return null;
    }
}
