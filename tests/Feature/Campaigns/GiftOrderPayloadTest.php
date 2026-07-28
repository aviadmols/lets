<?php

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\GiftShippingAddress;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Services\Shopify\Orders\ShopifyGiftOrderService;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\WooCommerce\Orders\WooGiftOrderService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * The exact shape of a free gift order on each platform.
 *
 * The shared idea: the line carries the product's FULL price and a discount takes
 * it to zero. A ₪0 line would be simpler and would lie — the merchant's reports
 * would show a pile of orders worth nothing instead of what they actually gave
 * away.
 */
final class GiftOrderPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    // === WooCommerce ===

    public function test_the_woo_line_carries_full_value_and_zero_total(): void
    {
        Http::fake(['*/wp-json/wc/v3/*' => Http::response(['id' => 9100], 201)]);

        [$shop, $campaign, $recipient] = $this->wooFixture(productId: 500, variantId: 500, price: 73.00);

        Tenant::run($shop, fn () => app(WooGiftOrderService::class)
            ->create($shop, $campaign, $recipient, $this->address()));

        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_ends_with($request->url(), '/wp-json/wc/v3/orders')) {
                return false;
            }
            $line = $request->data()['line_items'][0];

            return $line['product_id'] === 500
                && $line['subtotal'] === '73.00'   // what the gift is worth
                && $line['total'] === '0.00'       // what the customer pays
                // The W23 trap: a SIMPLE product's variant id equals its product id,
                // and echoing that back makes WooCommerce reject the whole order.
                && ! array_key_exists('variation_id', $line);
        });
    }

    public function test_a_real_variation_is_sent(): void
    {
        Http::fake(['*/wp-json/wc/v3/*' => Http::response(['id' => 9101], 201)]);

        [$shop, $campaign, $recipient] = $this->wooFixture(productId: 500, variantId: 777, price: 50.00);

        Tenant::run($shop, fn () => app(WooGiftOrderService::class)
            ->create($shop, $campaign, $recipient, $this->address()));

        Http::assertSent(fn (HttpRequest $r): bool => ! str_ends_with($r->url(), '/orders')
            || ($r->data()['line_items'][0]['variation_id'] ?? null) === 777);
    }

    public function test_the_woo_order_ships_free_and_is_marked_a_gift(): void
    {
        Http::fake(['*/wp-json/wc/v3/*' => Http::response(['id' => 9102], 201)]);

        [$shop, $campaign, $recipient] = $this->wooFixture(productId: 500, variantId: 500, price: 20.00);

        Tenant::run($shop, fn () => app(WooGiftOrderService::class)
            ->create($shop, $campaign, $recipient, $this->address()));

        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_ends_with($request->url(), '/wp-json/wc/v3/orders')) {
                return false;
            }
            $body = $request->data();
            $meta = collect($body['meta_data'])->pluck('value', 'key');

            return $body['status'] === 'processing'      // still has to be shipped
                && $body['set_paid'] === true            // nothing is owed
                && $body['shipping_lines'][0]['total'] === '0.00'
                && $body['shipping_lines'][0]['method_title'] === 'Gift shipping — free'
                && $body['shipping']['address_1'] === 'Herzl 1'
                // The mark the invoicing hooks look for — without it the plugin
                // would report a ₪0 order and ask for a tax document.
                && $meta['lets_order_role'] === 'gift_order';
        });
    }

    // === Shopify ===

    public function test_the_shopify_order_discounts_the_full_price_to_zero(): void
    {
        $recorder = new RecordingShopifyClient();
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        [$shop, $campaign, $recipient] = $this->shopifyFixture(variantId: 8800, price: 99.90);

        Tenant::run($shop, fn () => app(ShopifyGiftOrderService::class)
            ->create($shop, $campaign, $recipient, $this->address()));

        $order = $recorder->createdOrders[0];

        $this->assertSame(8800, $order['line_items'][0]['variant_id']);
        $this->assertSame('99.90', $order['line_items'][0]['price']);
        $this->assertSame('99.90', $order['discount_codes'][0]['amount']);
        $this->assertSame('fixed_amount', $order['discount_codes'][0]['type']);
        $this->assertSame('0.00', $order['shipping_lines'][0]['price']);
        $this->assertSame('Herzl 1', $order['shipping_address']['address1']);
        $this->assertSame('paid', $order['financial_status']);
    }

    public function test_the_shopify_gift_carries_no_transactions_block(): void
    {
        $recorder = new RecordingShopifyClient();
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        [$shop, $campaign, $recipient] = $this->shopifyFixture(variantId: 8801, price: 10.00);

        Tenant::run($shop, fn () => app(ShopifyGiftOrderService::class)
            ->create($shop, $campaign, $recipient, $this->address()));

        // The recurring-order precedent sends `transactions` to show real money as
        // Paid. After the discount this total is zero, and a zero-amount sale
        // transaction is not something Shopify accepts.
        $this->assertArrayNotHasKey('transactions', $recorder->createdOrders[0]);
    }

    public function test_the_shopify_gift_never_emails_a_receipt(): void
    {
        $recorder = new RecordingShopifyClient();
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        [$shop, $campaign, $recipient] = $this->shopifyFixture(variantId: 8802, price: 10.00);

        Tenant::run($shop, fn () => app(ShopifyGiftOrderService::class)
            ->create($shop, $campaign, $recipient, $this->address()));

        // A present is announced by the merchant, not by an order confirmation.
        $this->assertFalse($recorder->createdOrders[0]['send_receipt']);
    }

    // === Fixtures ===

    private function address(): GiftShippingAddress
    {
        return new GiftShippingAddress(
            firstName: 'Dana',
            lastName: 'Buyer',
            address1: 'Herzl 1',
            city: 'Tel Aviv',
            zip: '6100000',
            countryCode: 'IL',
        );
    }

    /** @return array{0: Shop, 1: GiftCampaign, 2: GiftRecipient} */
    private function wooFixture(int $productId, int $variantId, float $price): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'gift-payload.example.com',
            'name' => 'Gift Payload',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->woocommerce_credentials = [
            'base_url' => 'https://gift-payload.example.com',
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $shop->save();

        return $this->campaignFor($shop->fresh(), Product::SOURCE_WOOCOMMERCE, $productId, $variantId, $price);
    }

    /** @return array{0: Shop, 1: GiftCampaign, 2: GiftRecipient} */
    private function shopifyFixture(int $variantId, float $price): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'gift-payload.myshopify.com',
            'name' => 'Gift Payload',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        $shop->forceFill(['shopify_access_token' => 'shpat_x'])->save();

        return $this->campaignFor($shop->fresh(), Product::SOURCE_SHOPIFY, 4400, $variantId, $price);
    }

    /** @return array{0: Shop, 1: GiftCampaign, 2: GiftRecipient} */
    private function campaignFor(Shop $shop, string $source, int $productId, int $variantId, float $price): array
    {
        return Tenant::run($shop, function () use ($shop, $source, $productId, $variantId, $price): array {
            $product = new Product;
            $product->forceFill([
                'shop_id' => $shop->getKey(),
                'source' => $source,
                'external_id' => (string) $productId,
                'title' => 'Gift Product',
                'status' => Product::STATUS_ACTIVE,
            ])->save();

            $variant = new ProductVariant;
            $variant->forceFill([
                'shop_id' => $shop->getKey(),
                'product_id' => $product->getKey(),
                'external_variant_id' => (string) $variantId,
                'title' => 'Default',
                'price' => $price,
                'position' => 1,
            ])->save();

            $campaign = new GiftCampaign;
            $campaign->forceFill([
                'shop_id' => $shop->getKey(),
                'title' => 'Thank you',
                'min_cycles' => 3,
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant->getKey(),
                'product_title' => 'Gift Product',
                'unit_price' => $price,
                'currency' => 'ILS',
                'shipping_label' => 'Gift shipping — free',
                'status' => GiftCampaign::STATUS_GENERATING,
            ])->save();

            $recipient = new GiftRecipient;
            $recipient->forceFill([
                'shop_id' => $shop->getKey(),
                'gift_campaign_id' => $campaign->getKey(),
                'source_type' => GiftRecipient::SOURCE_PLAN,
                'source_id' => 1,
                'customer_name' => 'Dana Buyer',
                'customer_email' => 'dana@example.com',
                'status' => GiftRecipient::STATUS_PENDING,
                'currency' => 'ILS',
            ])->save();

            return [$shop, $campaign->fresh(), $recipient->fresh()];
        });
    }
}
