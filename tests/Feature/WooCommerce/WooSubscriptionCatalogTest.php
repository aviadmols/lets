<?php

namespace Tests\Feature\WooCommerce;

use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * W17 Part B — the subscription catalog lookup the plugin uses to mark products + render the
 * one-time/subscribe choice. /flags = which products carry an active subscription template;
 * /config = the resolved template + the SERVER-computed per-cycle price. Money-safe (price from the
 * catalog, never client) + tenant-safe (another shop's catalog is invisible) + unsigned → 401.
 */
final class WooSubscriptionCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const FLAGS = '/api/woocommerce/subscriptions/flags';
    private const CONFIG = '/api/woocommerce/subscriptions/config';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_flags_marks_only_products_with_an_active_subscription_template(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('sub-flags.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->makeSubscriptionProduct($shop, '501', 100.0);  // has a subscription template
            $this->makePlainProduct($shop, '502');                 // no template
        });

        $this->signedPost($key, $secret, self::FLAGS, ['product_ids' => ['501', '502', '999']])
            ->assertOk()
            ->assertJsonPath('flags.501', true)
            ->assertJsonPath('flags.502', false)
            ->assertJsonPath('flags.999', false);
    }

    public function test_config_returns_the_server_computed_per_cycle_price_and_cadence(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('sub-config.example.com');

        Tenant::run($shop, fn () => $this->makeSubscriptionProduct($shop, '501', 100.0, discountPercent: 10));

        $response = $this->signedPost($key, $secret, self::CONFIG, ['product_id' => '501', 'variant_id' => '501'])
            ->assertOk()
            ->assertJsonPath('has_subscription', true)
            ->assertJsonPath('one_time_allowed', false) // only a subscription template exists
            ->assertJsonPath('subscription.billing_frequency', 'monthly')
            ->assertJsonPath('subscription.interval_count', 1);

        // 100 − 10% = 90.00, computed server-side from the catalog + template (never the client).
        $this->assertEqualsWithDelta(90.0, (float) $response->json('subscription.price_per_cycle'), 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $response->json('subscription.base_price'), 0.001);
    }

    public function test_config_reports_one_time_allowed_when_a_one_time_template_also_exists(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('sub-onetime.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $product = $this->makeSubscriptionProduct($shop, '501', 100.0);
            // A second, one_time template on the same product → one-time also allowed.
            $this->makeTemplate($shop, $product, ProductSubscriptionPlan::TYPE_ONE_TIME);
        });

        $this->signedPost($key, $secret, self::CONFIG, ['product_id' => '501'])
            ->assertOk()
            ->assertJsonPath('has_subscription', true)
            ->assertJsonPath('one_time_allowed', true);
    }

    public function test_config_for_a_plain_product_has_no_subscription(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('sub-plain.example.com');
        Tenant::run($shop, fn () => $this->makePlainProduct($shop, '777'));

        $this->signedPost($key, $secret, self::CONFIG, ['product_id' => '777'])
            ->assertOk()
            ->assertJsonPath('has_subscription', false)
            ->assertJsonPath('one_time_allowed', true)
            ->assertJsonPath('subscription', null);
    }

    public function test_another_shops_product_is_invisible(): void
    {
        [$shopA] = $this->connectedShop('sub-iso-a.example.com');
        Tenant::run($shopA, fn () => $this->makeSubscriptionProduct($shopA, '501', 100.0));

        [, $keyB, $secretB] = $this->connectedShop('sub-iso-b.example.com');

        // Shop B asks about shop A's product id → not a subscription for B (tenant scope).
        $this->signedPost($keyB, $secretB, self::FLAGS, ['product_ids' => ['501']])
            ->assertOk()
            ->assertJsonPath('flags.501', false);
    }

    public function test_unsigned_is_rejected_401(): void
    {
        $this->postJson(self::FLAGS, ['product_ids' => ['1']])->assertStatus(401);
        $this->postJson(self::CONFIG, ['product_id' => '1'])->assertStatus(401);
    }

    // === Helpers ===

    private function makeSubscriptionProduct(Shop $shop, string $externalId, float $price, int $discountPercent = 0): Product
    {
        $product = $this->makePlainProduct($shop, $externalId, $price);
        $this->makeTemplate($shop, $product, ProductSubscriptionPlan::TYPE_SUBSCRIPTION, $discountPercent);

        return $product;
    }

    private function makePlainProduct(Shop $shop, string $externalId, float $price = 100.0): Product
    {
        $product = new Product;
        $product->forceFill([
            'shop_id' => $shop->id,
            'source' => Product::SOURCE_WOOCOMMERCE,
            'external_id' => $externalId,
            'title' => 'Product '.$externalId,
            'status' => Product::STATUS_ACTIVE,
            'online_store_status' => 'published',
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'shop_id' => $shop->id,
            'product_id' => $product->id,
            'external_variant_id' => $externalId, // simple WC product: variant id = product id
            'title' => '',
            'sku' => 'SKU-'.$externalId,
            'price' => $price,
            'position' => 0,
        ])->save();

        return $product;
    }

    private function makeTemplate(Shop $shop, Product $product, string $planType, int $discountPercent = 0): ProductSubscriptionPlan
    {
        $plan = new ProductSubscriptionPlan;
        $plan->fill([
            'product_id' => $product->id,
            'product_variant_id' => null, // all variants
            'plan_type' => $planType,
            'plan_kind' => 'recurring',
            'plan_name' => 'Monthly',
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'discount_type' => $discountPercent > 0 ? ProductSubscriptionPlan::DISCOUNT_PERCENT : ProductSubscriptionPlan::DISCOUNT_NONE,
            'discount_value' => $discountPercent,
            'channels' => [ProductSubscriptionPlan::CHANNEL_STOREFRONT_WIDGET],
            'position' => 0,
        ]);
        $plan->forceFill(['shop_id' => $shop->id, 'status' => ProductSubscriptionPlan::STATUS_ACTIVE])->save();

        return $plan;
    }

    /** @return array{0:Shop,1:string,2:string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp'];
        $shop->save();

        [$key, $secret] = $this->keys($result['connection_token']);

        return [$shop->fresh(), $key, $secret];
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

    /** @return array{0:string,1:string} */
    private function keys(string $token): array
    {
        $data = (array) json_decode((string) base64_decode(strtr($token, '-_', '+/')), true);

        return [(string) $data['k'], (string) $data['s']];
    }
}
