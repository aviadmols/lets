<?php

namespace Tests\Feature\WooCommerce;

use App\Filament\Pages\ManageBillingSettings;
use App\Models\MerchantBillingSettings;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Models\User;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → Billing → Subscribers' discount: the merchant picks a percent and
 * the store categories it covers; the plugin reads the rule off the account
 * shell. It hands out money, so the clamps are pinned beside the happy path.
 */
final class SubscriberDiscountSettingsTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const BOOTSTRAP = '/api/woocommerce/account/bootstrap';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_screen_saves_the_rule_and_the_shell_carries_it(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('sd-save.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->category('Books', '7');
            $this->category('Gifts', '9');
            $this->actingAs(User::factory()->forShop($shop)->create());

            Livewire::test(ManageBillingSettings::class)
                ->assertSet('data.subscriber_discount_enabled', false)
                ->set('data.subscriber_discount_enabled', true)
                ->set('data.subscriber_discount_percent', '20')
                // '999' is not a category this shop has — it must not be stored.
                ->set('data.subscriber_discount_category_ids', ['7', '999'])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame(
                ['percent' => 20.0, 'category_ids' => ['7']],
                MerchantBillingSettings::current()->fresh()->subscriberDiscountRule(),
            );
        });

        $this->signedPost($key, $secret, self::BOOTSTRAP, ['customer_ref' => ''])
            ->assertOk()
            ->assertJsonPath('account.purchase.subscriber_discount.percent', 20)
            ->assertJsonPath('account.purchase.subscriber_discount.category_ids', ['7']);
    }

    public function test_an_unconfigured_shop_discounts_nothing(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('sd-off.example.com');

        $this->signedPost($key, $secret, self::BOOTSTRAP, ['customer_ref' => ''])
            ->assertOk()
            ->assertJsonPath('account.purchase.subscriber_discount', null);

        Tenant::run($shop, function (): void {
            $settings = MerchantBillingSettings::current();

            // Switched on but with no category, or no percent: still nothing.
            $settings->forceFill(['subscriber_discount_enabled' => true, 'subscriber_discount_percent' => 20, 'subscriber_discount_category_ids' => []])->save();
            $this->assertNull($settings->fresh()->subscriberDiscountRule());

            $settings->forceFill(['subscriber_discount_percent' => null, 'subscriber_discount_category_ids' => ['7']])->save();
            $this->assertNull($settings->fresh()->subscriberDiscountRule());
        });
    }

    public function test_the_percent_is_clamped(): void
    {
        [$shop] = $this->connectedShop('sd-clamp.example.com');

        Tenant::run($shop, function (): void {
            $settings = MerchantBillingSettings::current();
            $settings->forceFill(['subscriber_discount_enabled' => true, 'subscriber_discount_percent' => 150, 'subscriber_discount_category_ids' => ['7', 'abc']])->save();

            $this->assertSame(
                ['percent' => (float) MerchantBillingSettings::MAX_SUBSCRIBER_DISCOUNT_PERCENT, 'category_ids' => ['7']],
                $settings->fresh()->subscriberDiscountRule(),
            );
        });
    }

    // === Fixtures ===

    private function category(string $name, string $externalId): void
    {
        $product = Product::create([
            'source' => Product::SOURCE_WOOCOMMERCE,
            'external_id' => 'p-'.$externalId,
            'title' => $name.' item',
            'status' => Product::STATUS_ACTIVE,
            'online_store_status' => Product::ONLINE_PUBLISHED,
        ]);

        ProductCategory::create(['product_id' => $product->id, 'external_category_id' => $externalId, 'name' => $name]);
    }

    /** @return array{0: Shop, 1: string, 2: string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $json = (string) base64_decode(strtr($result['connection_token'], '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [$result['shop']->fresh(), (string) $data['k'], (string) $data['s']];
    }

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
}
