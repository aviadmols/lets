<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Shopify\FakeProductShopifyClient;
use Tests\TestCase;

/**
 * W1 Phase C — product webhooks keep the cache fresh, tenant-bound.
 *
 * Drives the full transport (controller → ProcessShopifyWebhookJob →
 * ProductWebhookHandler) on the sync queue (phpunit default), so the shop-mismatch
 * guard + TenantContext + the shared ProductUpserter all run for real.
 *   - products/update: re-fetch via the source + upsert.
 *   - products/delete: mark the local row UNLISTED, KEEP it (and its plans).
 */
final class ProductWebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SECRET = 'test_platform_webhook_secret';
    private const ENDPOINT = '/shopify/webhooks';
    private const SHOP = 'alpha.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('shopify.webhook_secret', self::SECRET);
        config()->set('shopify.api_secret', self::SECRET);
    }

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_products_update_fetches_and_upserts_the_product(): void
    {
        $shop = $this->makeShop();

        // The handler calls fetchOne($gid) → the source resolves it via the client.
        $gid = 'gid://shopify/Product/7001';
        $node = FakeProductShopifyClient::productNode($gid, 'Webhook Tonic', sku: 'WH-SKU');
        ShopifyClientFactory::fake(fn (Shop $s) => new FakeProductShopifyClient(pages: [], byGid: [$gid => $node]));

        $this->postWebhook('products/update', [
            'id' => 7001,
            'admin_graphql_api_id' => $gid,
            'title' => 'Webhook Tonic',
        ], 'wh-prod-1')->assertStatus(202);

        Tenant::set($shop);
        $product = Product::query()->where('external_id', '7001')->firstOrFail();
        $this->assertSame('Webhook Tonic', $product->title);
        $this->assertSame($shop->id, $product->shop_id);
        $this->assertSame('WH-SKU', $product->variants()->first()->sku);
    }

    public function test_products_delete_marks_local_product_unlisted_and_keeps_it(): void
    {
        $shop = $this->makeShop();

        // Seed an existing cached product + variant for this shop.
        Tenant::run($shop, function (): void {
            $product = Product::create([
                'source' => Product::SOURCE_SHOPIFY,
                'external_id' => '8001',
                'title' => 'To Be Deleted',
                'status' => Product::STATUS_ACTIVE,
                'online_store_status' => Product::ONLINE_PUBLISHED,
            ]);
            ProductVariant::create([
                'product_id' => $product->id,
                'external_variant_id' => '800101',
                'sku' => 'DEL-SKU',
                'price' => 10,
            ]);
        });
        Tenant::clear();

        $this->postWebhook('products/delete', [
            'id' => 8001,
            'admin_graphql_api_id' => 'gid://shopify/Product/8001',
        ], 'wh-prod-del')->assertStatus(202);

        Tenant::set($shop);
        $product = Product::query()->where('external_id', '8001')->firstOrFail();
        $this->assertSame(Product::STATUS_UNLISTED, $product->status, 'Deleted upstream ⇒ soft-removed locally.');
        $this->assertSame(1, $product->variants()->count(), 'Variants (and plans) are KEPT on delete.');
    }

    // === Helpers ===

    private function makeShop(): Shop
    {
        return Shop::create([
            'shopify_domain' => self::SHOP,
            'name' => self::SHOP,
            'platform' => Shop::PLATFORM_SHOPIFY,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    private function postWebhook(string $topic, array $payload, string $webhookId)
    {
        $raw = json_encode($payload);
        $hmac = base64_encode(hash_hmac('sha256', $raw, self::SECRET, true));

        return $this->call('POST', self::ENDPOINT, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => self::SHOP,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
        ], $raw);
    }
}
