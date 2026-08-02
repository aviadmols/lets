<?php

namespace Tests\Feature\Orders;

use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Domain\Campaigns\GiftShippingAddress;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\ChargeContext;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Shopify\Orders\DefaultShopifyOrderStrategy;
use App\Services\Shopify\Orders\ShopifyGiftOrderService;
use App\Services\Shopify\Orders\ShopifyOrderTags;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\Shopify\ShopifyToken;
use App\Services\WooCommerce\Orders\WooCommerceOrderStrategy;
use App\Services\WooCommerce\Orders\WooGiftOrderService;
use App\Services\WooCommerce\Orders\WooOrderTags;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * Which orders in this store did the app create?
 *
 * A merchant opening their store finds orders they did not place — subscription
 * cycles, upsell children, gifts. Each already said what KIND it was; none said
 * who made it. The umbrella tag answers that in one filter, and it must never be
 * the thing a new order type forgets.
 */
final class OrderTagsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    // === Shopify ===

    public function test_the_umbrella_tag_leads_and_the_kind_tag_survives(): void
    {
        $line = ShopifyOrderTags::line('gift_order');

        $this->assertSame('LETS, lets-gift', $line);
    }

    public function test_an_unconfigured_kind_is_dropped_not_emitted_blank(): void
    {
        // A blank tag becomes a nameless entry in the merchant's Shopify filter
        // list — worse than no tag.
        $this->assertSame('LETS', ShopifyOrderTags::line('no_such_kind'));
        $this->assertStringNotContainsString(', ,', ShopifyOrderTags::line('no_such_kind', 'gift_order'));
    }

    public function test_the_same_kind_twice_is_one_tag(): void
    {
        $this->assertSame('LETS, lets-gift', ShopifyOrderTags::line('gift_order', 'gift_order'));
    }

    public function test_a_created_shopify_order_carries_both_tags(): void
    {
        $recorder = new RecordingShopifyClient();
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        [$shop, $campaign, $recipient] = $this->shopifyGift();

        Tenant::run($shop, fn () => app(ShopifyGiftOrderService::class)
            ->create($shop, $campaign, $recipient, $this->address()));

        $tags = $recorder->createdOrders[0]['tags'];
        $this->assertStringContainsString(ShopifyOrderTags::umbrella(), $tags);
        $this->assertStringContainsString('lets-gift', $tags);
    }

    public function test_the_hold_to_paid_swap_does_not_strip_the_umbrella(): void
    {
        // FulfillmentLockService::release() diffs only the four named installment
        // tags and keeps the rest. If it ever replaced the whole set instead, every
        // paid installment order would silently lose its LETS mark.
        $current = ['LETS', 'installment_plan_active', 'installments-hold', 'a-merchant-tag'];
        $tags = (array) config('shopify.tags');

        $next = array_values(array_unique(array_filter(array_merge(
            array_diff($current, [$tags['installments_hold'], $tags['installments_active']]),
            [$tags['paid_release'], $tags['ready_to_fulfill']],
        ))));

        $this->assertContains('LETS', $next);
        $this->assertContains('a-merchant-tag', $next);
        $this->assertNotContains('installments-hold', $next);
    }

    // === WooCommerce ===

    public function test_the_woo_tag_line_mirrors_the_shopify_shape(): void
    {
        $this->assertSame('LETS, gift', WooOrderTags::line(WooOrderTags::KIND_GIFT));
        $this->assertSame('LETS', WooOrderTags::line());
    }

    public function test_a_created_woo_order_carries_the_tag_meta(): void
    {
        Http::fake(['*/wp-json/wc/v3/*' => Http::response(['id' => 9200], 201)]);

        [$shop, $campaign, $recipient] = $this->wooGift();

        Tenant::run($shop, fn () => app(WooGiftOrderService::class)
            ->create($shop, $campaign, $recipient, $this->address()));

        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_ends_with($request->url(), '/wp-json/wc/v3/orders')) {
                return false;
            }
            $meta = collect($request->data()['meta_data'])->pluck('value', 'key');

            // WooCommerce has no order tags, so this meta IS the tag — it is what
            // the plugin's orders-list column renders.
            return $meta[WooOrderTags::META_TAGS] === 'LETS, gift'
                && $meta[WooOrderTags::META_ROLE] === 'gift_order';
        });
    }

    // === The subscription-discount tag (pricing modes / intro window) ===

    public function test_a_discounted_shopify_cycle_order_carries_the_discount_tag(): void
    {
        $recorder = new RecordingShopifyClient();
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $shop = $this->installedShopifyShop('disc-tag.myshopify.com');
        // Charged 80 against a regular price of 100 ⇒ a discounted cycle.
        $plan = $this->recurringPlan($shop, installmentAmount: 80, regularAmount: 100);

        Tenant::run($shop, fn () => (new DefaultShopifyOrderStrategy())->materialize($plan, ChargeContext::RECURRING));

        $tags = $recorder->createdOrders[0]['tags'];
        $this->assertStringContainsString('subscription-discount', $tags);
        $this->assertStringContainsString('subscription-recurring', $tags);
    }

    public function test_a_full_price_shopify_cycle_order_has_no_discount_tag(): void
    {
        $recorder = new RecordingShopifyClient();
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $shop = $this->installedShopifyShop('full-tag.myshopify.com');
        // Charged exactly the regular price ⇒ nothing to claim.
        $plan = $this->recurringPlan($shop, installmentAmount: 100, regularAmount: 100);

        Tenant::run($shop, fn () => (new DefaultShopifyOrderStrategy())->materialize($plan, ChargeContext::RECURRING));

        $this->assertStringNotContainsString('subscription-discount', $recorder->createdOrders[0]['tags']);
    }

    public function test_a_discounted_woo_cycle_order_carries_the_discount_kind(): void
    {
        Http::fake(['*/wp-json/wc/v3/*' => Http::response(['id' => 9300], 201)]);

        $shop = $this->connectedWooShop('disc-tag.example.com');
        $plan = $this->recurringPlan($shop, installmentAmount: 80, regularAmount: 100);

        Tenant::run($shop, fn () => (new WooCommerceOrderStrategy())->materialize($plan, ChargeContext::RECURRING));

        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_ends_with($request->url(), '/wp-json/wc/v3/orders')) {
                return false;
            }
            $meta = collect($request->data()['meta_data'])->pluck('value', 'key');

            return $meta[WooOrderTags::META_TAGS] === 'LETS, recurring, discount';
        });
    }

    public function test_a_legacy_plan_without_a_regular_amount_is_never_tagged_discounted(): void
    {
        $recorder = new RecordingShopifyClient();
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $shop = $this->installedShopifyShop('legacy-tag.myshopify.com');
        $plan = $this->recurringPlan($shop, installmentAmount: 80, regularAmount: null);

        Tenant::run($shop, fn () => (new DefaultShopifyOrderStrategy())->materialize($plan, ChargeContext::RECURRING));

        $this->assertStringNotContainsString('subscription-discount', $recorder->createdOrders[0]['tags']);
    }

    // === Fixtures ===

    private function installedShopifyShop(string $domain): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->captureShopifyInstall(new ShopifyToken('shpat_token_'.$domain, 'read_orders,write_orders', 86400));

        return $shop->fresh();
    }

    private function connectedWooShop(string $domain): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->woocommerce_credentials = [
            'base_url' => 'https://'.$domain,
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $shop->save();

        return $shop->fresh();
    }

    private function recurringPlan(Shop $shop, float $installmentAmount, ?float $regularAmount): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($installmentAmount, $regularAmount): InstallmentPlan {
            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'total_amount' => $installmentAmount,
                'total_charged' => 0,
                'installment_amount' => $installmentAmount,
                'regular_amount' => $regularAmount,
                'currency' => 'ILS',
                'public_id' => 'PLAN-'.uniqid(),
                'customer_email' => 'buyer@example.com',
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            return $plan;
        });
    }

    private function address(): GiftShippingAddress
    {
        return new GiftShippingAddress(
            firstName: 'Dana', lastName: 'Buyer', address1: 'Herzl 1',
            city: 'Tel Aviv', zip: '6100000', countryCode: 'IL',
        );
    }

    /** @return array{0: Shop, 1: GiftCampaign, 2: GiftRecipient} */
    private function shopifyGift(): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'tags.myshopify.com',
            'name' => 'Tags',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        $shop->forceFill(['shopify_access_token' => 'shpat_x'])->save();

        return $this->giftFixture($shop->fresh(), Product::SOURCE_SHOPIFY);
    }

    /** @return array{0: Shop, 1: GiftCampaign, 2: GiftRecipient} */
    private function wooGift(): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'tags.example.com',
            'name' => 'Tags',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->woocommerce_credentials = [
            'base_url' => 'https://tags.example.com',
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $shop->save();

        return $this->giftFixture($shop->fresh(), Product::SOURCE_WOOCOMMERCE);
    }

    /** @return array{0: Shop, 1: GiftCampaign, 2: GiftRecipient} */
    private function giftFixture(Shop $shop, string $source): array
    {
        return Tenant::run($shop, function () use ($shop, $source): array {
            $product = new Product;
            $product->forceFill([
                'shop_id' => $shop->getKey(),
                'source' => $source,
                'external_id' => '500',
                'title' => 'Gift',
                'status' => Product::STATUS_ACTIVE,
            ])->save();

            $variant = new ProductVariant;
            $variant->forceFill([
                'shop_id' => $shop->getKey(),
                'product_id' => $product->getKey(),
                'external_variant_id' => '500',
                'price' => 40.00,
                'position' => 1,
            ])->save();

            $campaign = new GiftCampaign;
            $campaign->forceFill([
                'shop_id' => $shop->getKey(),
                'title' => 'Thanks',
                'min_cycles' => 1,
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant->getKey(),
                'product_title' => 'Gift',
                'unit_price' => 40.00,
                'currency' => 'ILS',
                'shipping_label' => 'Gift shipping',
                'status' => GiftCampaign::STATUS_GENERATING,
            ])->save();

            $recipient = new GiftRecipient;
            $recipient->forceFill([
                'shop_id' => $shop->getKey(),
                'gift_campaign_id' => $campaign->getKey(),
                'source_type' => GiftRecipient::SOURCE_PLAN,
                'source_id' => 1,
                'customer_email' => 'dana@example.com',
                'status' => GiftRecipient::STATUS_PENDING,
                'currency' => 'ILS',
            ])->save();

            return [$shop, $campaign->fresh(), $recipient->fresh()];
        });
    }
}
