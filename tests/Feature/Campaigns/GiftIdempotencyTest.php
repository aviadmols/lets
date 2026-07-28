<?php

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\GiftCampaignGenerator;
use App\Domain\Campaigns\Jobs\GiftOrderJob;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Nobody gets two packages.
 *
 * Neither WooCommerce nor Shopify accepts an idempotency key on order creation, so
 * unlike a charge there is nothing store-side to collapse a duplicate. The
 * recipient row is the whole wall — enrolled once by a unique index, claimed once
 * before the API call.
 */
final class GiftIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_generating_twice_enrols_each_subscriber_once(): void
    {
        Queue::fake();
        $shop = $this->shop();
        Tenant::set($shop);
        $this->plan($shop, 'Dana', succeeded: 4);

        $campaign = $this->campaign($shop);

        $first = app(GiftCampaignGenerator::class)->generate($shop, $campaign);
        $second = app(GiftCampaignGenerator::class)->generate($shop, $campaign->fresh());

        $this->assertSame(1, $first['enrolled']);
        // The second run sees them as already enrolled and adds nothing.
        $this->assertSame(0, $second['enrolled']);
        $this->assertSame(1, $second['already']);
        $this->assertSame(1, GiftRecipient::query()->count());
    }

    public function test_a_re_run_dispatches_nothing_for_someone_already_served(): void
    {
        Queue::fake();
        $shop = $this->shop();
        Tenant::set($shop);
        $this->plan($shop, 'Dana', succeeded: 4);

        $campaign = $this->campaign($shop);
        app(GiftCampaignGenerator::class)->generate($shop, $campaign);

        // Their gift landed.
        GiftRecipient::query()->first()->markCreated('9001', GiftRecipient::ADDRESS_FROM_PROFILE);

        Queue::fake(); // reset the recorder
        app(GiftCampaignGenerator::class)->generate($shop, $campaign->fresh());

        Queue::assertNothingPushed();
    }

    public function test_a_re_run_picks_up_a_newcomer(): void
    {
        Queue::fake();
        $shop = $this->shop();
        Tenant::set($shop);
        $this->plan($shop, 'Dana', succeeded: 4);

        $campaign = $this->campaign($shop);
        app(GiftCampaignGenerator::class)->generate($shop, $campaign);
        GiftRecipient::query()->first()->markCreated('9001', null);

        // A second subscriber crosses the threshold later.
        $this->plan($shop, 'Meir', succeeded: 4, email: 'meir@example.com');

        $result = app(GiftCampaignGenerator::class)->generate($shop, $campaign->fresh());

        $this->assertSame(1, $result['enrolled']);
        $this->assertSame(1, $result['dispatched']);
        $this->assertSame(2, GiftRecipient::query()->count());
    }

    public function test_a_redelivered_job_cannot_create_a_second_order(): void
    {
        // ONE fake: Http::fake() APPENDS stubs, so a catch-all registered first
        // would answer the customer read before the specific pattern got a look.
        Http::fake([
            '*/wp-json/wc/v3/customers/*' => Http::response([
                'id' => 55,
                'shipping' => ['address_1' => 'Herzl 1', 'city' => 'Tel Aviv', 'country' => 'IL'],
            ], 200),
            '*/wp-json/wc/v3/orders' => Http::response(['id' => 9500], 201),
            '*/wp-json/wc/v3/*' => Http::response([], 200),
        ]);
        $shop = $this->shop();
        Tenant::set($shop);
        $plan = $this->plan($shop, 'Dana', succeeded: 4);
        $campaign = $this->campaign($shop);

        $recipient = new GiftRecipient;
        $recipient->forceFill([
            'shop_id' => $shop->getKey(),
            'gift_campaign_id' => $campaign->getKey(),
            'source_type' => GiftRecipient::SOURCE_PLAN,
            'source_id' => $plan->getKey(),
            'customer_email' => 'dana@example.com',
            'status' => GiftRecipient::STATUS_PENDING,
            'currency' => 'ILS',
        ])->save();

        $job = new GiftOrderJob((int) $shop->getKey(), (int) $campaign->getKey(), (int) $recipient->getKey());

        $job->handle(app(\App\Domain\Campaigns\GiftAddressResolver::class));
        $job->handle(app(\App\Domain\Campaigns\GiftAddressResolver::class));

        // The second delivery finds the row already claimed and stops before the
        // API — the only thing standing between a redelivery and a second package.
        // Exactly ONE order created — asserted on the create call itself rather
        // than a total request count, because that is the thing that would ship a
        // second package.
        $creates = Http::recorded(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/wp-json/wc/v3/orders'));

        $this->assertCount(1, $creates);
        $this->assertSame(GiftRecipient::STATUS_CREATED, $recipient->fresh()->status);
    }

    public function test_a_stuck_creating_row_is_never_picked_up_again(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $shop = $this->shop();
        Tenant::set($shop);
        $plan = $this->plan($shop, 'Dana', succeeded: 4);
        $campaign = $this->campaign($shop);

        $recipient = new GiftRecipient;
        $recipient->forceFill([
            'shop_id' => $shop->getKey(),
            'gift_campaign_id' => $campaign->getKey(),
            'source_type' => GiftRecipient::SOURCE_PLAN,
            'source_id' => $plan->getKey(),
            'status' => GiftRecipient::STATUS_CREATING, // a worker died mid-flight
            'currency' => 'ILS',
        ])->save();

        (new GiftOrderJob((int) $shop->getKey(), (int) $campaign->getKey(), (int) $recipient->getKey()))
            ->handle(app(\App\Domain\Campaigns\GiftAddressResolver::class));

        // The order may already exist in the store. A missing gift is a button
        // click; a duplicate is a package the merchant pays to ship twice.
        Http::assertNothingSent();
        $this->assertSame(GiftRecipient::STATUS_CREATING, $recipient->fresh()->status);
    }

    public function test_only_a_rejected_attempt_may_be_retried(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);
        $campaign = $this->campaign($shop);

        $failed = $this->recipient($shop, $campaign, GiftRecipient::STATUS_FAILED, 1);
        $creating = $this->recipient($shop, $campaign, GiftRecipient::STATUS_CREATING, 2);

        $this->assertTrue($failed->resetForRetry());
        // `failed` means the platform said no, so nothing exists to duplicate.
        $this->assertSame(GiftRecipient::STATUS_PENDING, $failed->fresh()->status);

        $this->assertFalse($creating->resetForRetry());
        $this->assertSame(GiftRecipient::STATUS_CREATING, $creating->fresh()->status);
    }

    // === Fixtures ===

    private function shop(): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'gift-idem.example.com',
            'name' => 'Gift Idem',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->woocommerce_credentials = [
            'base_url' => 'https://gift-idem.example.com',
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $shop->save();

        return $shop->fresh();
    }

    private function campaign(Shop $shop): GiftCampaign
    {
        return Tenant::run($shop, function () use ($shop): GiftCampaign {
            $product = new Product;
            $product->forceFill([
                'shop_id' => $shop->getKey(),
                'source' => Product::SOURCE_WOOCOMMERCE,
                'external_id' => '500',
                'title' => 'Gift',
                'status' => Product::STATUS_ACTIVE,
            ])->save();

            $variant = new ProductVariant;
            $variant->forceFill([
                'shop_id' => $shop->getKey(),
                'product_id' => $product->getKey(),
                'external_variant_id' => '500',
                'price' => 25.00,
                'position' => 1,
            ])->save();

            $campaign = new GiftCampaign;
            $campaign->forceFill([
                'shop_id' => $shop->getKey(),
                'title' => 'Thanks',
                'min_cycles' => 3,
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant->getKey(),
                'product_title' => 'Gift',
                'unit_price' => 25.00,
                'currency' => 'ILS',
                'shipping_label' => 'Gift shipping',
                'status' => GiftCampaign::STATUS_DRAFT,
            ])->save();

            return $campaign->fresh();
        });
    }

    private function recipient(Shop $shop, GiftCampaign $campaign, string $status, int $sourceId): GiftRecipient
    {
        $recipient = new GiftRecipient;
        $recipient->forceFill([
            'shop_id' => $shop->getKey(),
            'gift_campaign_id' => $campaign->getKey(),
            'source_type' => GiftRecipient::SOURCE_PLAN,
            'source_id' => $sourceId,
            'status' => $status,
            'currency' => 'ILS',
        ])->save();

        return $recipient;
    }

    private function plan(Shop $shop, string $name, int $succeeded, string $email = 'dana@example.com'): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $name, $succeeded, $email): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'total_amount' => 100,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'public_id' => (string) Str::ulid(),
                'customer_name' => $name,
                'customer_email' => $email,
                'external_customer_id' => '55',
            ]);
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => PlanStatus::ACTIVE->value,
            ])->save();

            for ($i = 1; $i <= $succeeded; $i++) {
                $payment = new InstallmentPayment;
                $payment->forceFill([
                    'shop_id' => (int) $shop->getKey(),
                    'plan_id' => $plan->getKey(),
                    'payment_type' => PaymentType::RECURRING->value,
                    'sequence' => $i,
                    'amount' => 100,
                    'currency' => 'ILS',
                    'status' => PaymentStatus::SUCCEEDED->value,
                ])->save();
            }

            return $plan;
        });
    }
}
