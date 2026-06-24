<?php

namespace Tests\Feature\Platform;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * W11 Phase 0 — the additive WooCommerce schema + Shop helpers. Proves the migrations
 * run, the WC creds + lets_api_secret encrypt at rest, hasWooConnection() derives from
 * the bag, a WooCommerce shop is valid WITHOUT a shopify_domain (now nullable), and the
 * platform-neutral external order id falls back to the legacy column.
 */
final class ShopWooCommerceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_woocommerce_credentials_encrypt_at_rest_and_round_trip(): void
    {
        $shop = Shop::create([
            'name' => 'WC',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'woocommerce_domain' => 'store.example.com',
        ]);
        $this->assertFalse($shop->hasWooConnection());

        $shop->woocommerce_credentials = [
            'base_url' => 'https://store.example.com',
            'consumer_key' => 'ck_123',
            'consumer_secret' => 'cs_456',
            'wc_webhook_secret' => 'whs_789',
        ];
        $shop->lets_api_secret = 'super_secret';
        $shop->save();

        $fresh = $shop->fresh();
        $this->assertTrue($fresh->hasWooConnection());
        $this->assertSame('ck_123', $fresh->wooCredential('consumer_key'));
        $this->assertSame('https://store.example.com', $fresh->wooConfig()['base_url']);
        $this->assertSame('super_secret', $fresh->lets_api_secret);

        // Encrypted at rest: the raw DB column never holds the plaintext secret.
        $raw = (string) DB::table('shops')->where('id', $shop->getKey())->value('woocommerce_credentials');
        $this->assertStringNotContainsString('ck_123', $raw);
        $this->assertStringNotContainsString('cs_456', $raw);
    }

    public function test_woocommerce_shop_is_valid_without_a_shopify_domain(): void
    {
        $shop = Shop::create([
            'name' => 'WC2',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'woocommerce_domain' => 'store2.example.com',
        ]);

        $this->assertNull($shop->shopify_domain);
        $this->assertDatabaseHas('shops', [
            'woocommerce_domain' => 'store2.example.com',
            'shopify_domain' => null,
        ]);
    }

    public function test_external_order_id_falls_back_to_the_legacy_shopify_column(): void
    {
        $plan = new InstallmentPlan(['shopify_order_id' => 'SHOP-1']);
        $this->assertSame('SHOP-1', $plan->externalOrderId());

        $plan->external_order_id = 'WC-9';
        $this->assertSame('WC-9', $plan->externalOrderId());
    }
}
