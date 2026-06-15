<?php

namespace Tests\Feature\Shopify;

use App\Models\Shop;
use App\Models\WebhookEvent;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The webhook transport contract (shopify-integration.md §4):
 *   - raw-body HMAC verify: good signature accepted, bad/absent rejected 401.
 *   - empty platform secret in production ⇒ 503 (fail closed).
 *   - routed by X-Shopify-Shop-Domain; a Shop A webhook binds Shop A only.
 *   - deduped by webhook_id (at-least-once delivery ⇒ processed once).
 *   - app/uninstalled marks the shop uninstalled + revokes the token.
 */
final class ShopifyWebhookTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SECRET = 'test_platform_webhook_secret';
    private const ENDPOINT = '/shopify/webhooks';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('shopify.webhook_secret', self::SECRET);
        config()->set('shopify.api_secret', self::SECRET);
        // Default: jobs run on the sync queue (phpunit QUEUE_CONNECTION=sync) so
        // the full verify→persist→process path executes end-to-end. Tests that
        // assert DISPATCH (not effect) call Queue::fake() themselves.
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_correctly_signed_webhook_is_accepted_and_persisted(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $payload = ['id' => 9001, 'name' => '#1001'];

        $response = $this->postWebhook('orders/paid', $shop->shopify_domain, $payload, 'wh-1');

        $response->assertStatus(202);
        $this->assertDatabaseHas('webhook_events', [
            'shop_id' => $shop->id,
            'topic' => 'orders/paid',
            'webhook_id' => 'wh-1',
            'hmac_valid' => true,
        ]);
    }

    public function test_bad_hmac_is_rejected_401_and_not_persisted(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $raw = json_encode(['id' => 1]);

        $response = $this->call(
            'POST',
            self::ENDPOINT,
            [],
            [],
            [],
            $this->serverHeaders('orders/paid', $shop->shopify_domain, 'wh-x', hmac: 'not-the-right-signature'),
            $raw,
        );

        $response->assertStatus(401);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_absent_hmac_is_rejected_401(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $raw = json_encode(['id' => 1]);

        $headers = $this->serverHeaders('orders/paid', $shop->shopify_domain, 'wh-x', hmac: null);

        $response = $this->call('POST', self::ENDPOINT, [], [], [], $headers, $raw);

        $response->assertStatus(401);
    }

    public function test_empty_secret_in_production_returns_503(): void
    {
        config()->set('shopify.webhook_secret', '');
        app()->detectEnvironment(fn () => 'production');

        $shop = $this->makeShop('alpha.myshopify.com');
        $raw = json_encode(['id' => 1]);
        $headers = $this->serverHeaders('orders/paid', $shop->shopify_domain, 'wh-x', hmac: 'anything');

        $response = $this->call('POST', self::ENDPOINT, [], [], [], $headers, $raw);

        $response->assertStatus(503);
    }

    public function test_webhook_routes_to_correct_shop_and_never_leaks_to_other_shop(): void
    {
        $shopA = $this->makeShop('alpha.myshopify.com');
        $shopB = $this->makeShop('beta.myshopify.com');

        $this->postWebhook('orders/paid', $shopA->shopify_domain, ['id' => 1], 'wh-A')->assertStatus(202);

        // The event is bound to Shop A only — Shop B has nothing.
        $this->assertDatabaseHas('webhook_events', ['shop_id' => $shopA->id, 'webhook_id' => 'wh-A']);
        $this->assertDatabaseMissing('webhook_events', ['shop_id' => $shopB->id]);
        $this->assertSame(1, WebhookEvent::query()->where('shop_id', $shopA->id)->count());
    }

    public function test_duplicate_webhook_id_is_deduped_to_one_row(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');

        $this->postWebhook('orders/paid', $shop->shopify_domain, ['id' => 5], 'dup-1')->assertStatus(202);
        $this->postWebhook('orders/paid', $shop->shopify_domain, ['id' => 5], 'dup-1')
            ->assertStatus(202)
            ->assertJsonPath('status', 'duplicate_accepted');

        $this->assertSame(1, WebhookEvent::query()
            ->where('shop_id', $shop->id)->where('webhook_id', 'dup-1')->count());
    }

    public function test_unknown_shop_domain_returns_202_without_persisting(): void
    {
        $response = $this->postWebhook('orders/paid', 'ghost.myshopify.com', ['id' => 1], 'wh-ghost');

        $response->assertStatus(202)->assertJsonPath('status', 'unknown_shop');
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_processing_binds_the_correct_tenant_and_never_crosses_shops(): void
    {
        $shopA = $this->makeShop('alpha.myshopify.com');
        $this->makeShop('beta.myshopify.com');

        // Capture the tenant that is bound while the order-paid handler runs.
        $boundShopId = null;
        \Illuminate\Support\Facades\Event::listen('shopify.order.paid', function (array $data) use (&$boundShopId): void {
            // The handler runs with the tenant bound; both the event payload AND
            // the live Tenant must point at Shop A.
            $boundShopId = [
                'payload' => (int) ($data['shop_id'] ?? 0),
                'bound' => Tenant::id(),
            ];
        });

        $this->postWebhook('orders/paid', $shopA->shopify_domain, ['id' => 42], 'wh-tenant')->assertStatus(202);

        $this->assertNotNull($boundShopId, 'The order-paid handler must have run.');
        $this->assertSame($shopA->id, $boundShopId['payload'], 'Event payload must carry Shop A.');
        $this->assertSame($shopA->id, $boundShopId['bound'], 'Tenant must be bound to Shop A during processing.');

        // Tenant context is cleared after the job (no leak to the next worker job).
        $this->assertNull(Tenant::current());
    }

    public function test_app_uninstalled_marks_shop_uninstalled_and_revokes_token(): void
    {
        // Runs on the sync queue (phpunit default) so the handler executes
        // end-to-end: controller → ProcessShopifyWebhookJob → AppUninstalledHandler.
        $shop = $this->makeShop('alpha.myshopify.com');
        $shop->captureShopifyInstall('shpat_live_token', 'read_orders');
        $this->assertNotNull($shop->fresh()->shopifyAccessToken());

        $this->postWebhook('app/uninstalled', $shop->shopify_domain, ['domain' => $shop->shopify_domain], 'wh-uninstall')
            ->assertStatus(202);

        $fresh = $shop->fresh();
        $this->assertSame(Shop::STATUS_UNINSTALLED, $fresh->status);
        $this->assertNotNull($fresh->uninstalled_at);
        $this->assertNull($fresh->shopifyAccessToken(), 'Token must be revoked on uninstall.');
        $this->assertFalse($fresh->isLive());
    }

    // === Helpers ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    private function postWebhook(string $topic, string $domain, array $payload, string $webhookId)
    {
        $raw = json_encode($payload);
        $hmac = base64_encode(hash_hmac('sha256', $raw, self::SECRET, true));

        return $this->call(
            'POST',
            self::ENDPOINT,
            [],
            [],
            [],
            $this->serverHeaders($topic, $domain, $webhookId, $hmac),
            $raw,
        );
    }

    /** @return array<string, string> */
    private function serverHeaders(string $topic, string $domain, string $webhookId, ?string $hmac): array
    {
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $domain,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'HTTP_X_SHOPIFY_API_VERSION' => '2025-10',
        ];
        if ($hmac !== null) {
            $headers['HTTP_X_SHOPIFY_HMAC_SHA256'] = $hmac;
        }

        return $headers;
    }
}
