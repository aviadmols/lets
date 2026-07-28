<?php

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\Jobs\GiftOrderJob;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Filament\Pages\GiftOrders;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screen a merchant uses to give away real inventory.
 *
 * Its job is to make the consequence visible BEFORE it happens: you see exactly
 * who qualifies, then you confirm. So the gates tested here are the ones that stop
 * an order being created from an incomplete rule — a campaign with no gift, or a
 * gift with no value to print on it.
 */
final class GiftOrdersPageTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'gift-page.example.com',
            'name' => 'Gift Page',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_page_renders_its_zones(): void
    {
        Livewire::test(GiftOrders::class)
            ->assertOk()
            ->assertSee(__('gifts.rule_heading'))
            // Neither list is on screen before it means anything: the recipients
            // until they are asked for, the history until there is one.
            ->assertDontSee(__('gifts.preview_heading'))
            ->assertDontSee(__('gifts.past_heading'))
            ->call('preview')
            ->assertSee(__('gifts.preview_heading'));

        $this->campaign();

        Livewire::test(GiftOrders::class)->assertSee(__('gifts.past_heading'));
    }

    public function test_nobody_is_listed_until_the_merchant_asks(): void
    {
        $this->subscriber('Dana', succeeded: 4);

        $page = Livewire::test(GiftOrders::class)->set('minCycles', 3);

        // The list is not a live side effect of typing a threshold — it appears
        // when the merchant asks for it, which is what they are then confirming.
        $this->assertCount(0, $page->instance()->qualifying());

        $page->call('preview');
        $this->assertCount(1, $page->instance()->qualifying());
    }

    public function test_changing_the_gift_retires_the_preview(): void
    {
        $this->subscriber('Dana', succeeded: 4);
        [$product] = $this->giftProduct(price: 30.00);

        $page = Livewire::test(GiftOrders::class)
            ->set('minCycles', 3)
            ->call('preview')
            ->assertSet('previewed', true)
            // A list shown for one gift must not stand as approval for a different
            // one.
            ->call('selectProduct', (int) $product->getKey())
            ->assertSet('previewed', false);

        $this->assertCount(0, $page->instance()->qualifying());
    }

    public function test_a_campaign_without_a_gift_creates_nothing(): void
    {
        Queue::fake();
        $this->subscriber('Dana', succeeded: 4);

        Livewire::test(GiftOrders::class)
            ->set('campaignTitle', 'Thank you')
            ->set('minCycles', 3)
            ->call('preview')
            ->call('generate');

        $this->assertSame(0, GiftCampaign::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_priceless_gift_is_refused_rather_than_valued_at_zero(): void
    {
        Queue::fake();
        $this->subscriber('Dana', succeeded: 4);
        // The price column is NOT NULL defaulting to 0, so a product whose price
        // never survived the sync arrives looking exactly like this.
        [$product] = $this->giftProduct(price: 0.0);

        Livewire::test(GiftOrders::class)
            ->set('campaignTitle', 'Thank you')
            ->set('minCycles', 3)
            ->call('selectProduct', (int) $product->getKey())
            ->call('preview')
            ->call('generate');

        // Inventing a value would print a present "worth nothing" on the order and
        // in every report built off it.
        $this->assertSame(0, GiftCampaign::query()->count());
        $this->assertSame(0, GiftRecipient::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_generating_snapshots_the_gift_and_enrols_the_qualifiers(): void
    {
        Queue::fake();
        $this->subscriber('Dana', succeeded: 4);
        $this->subscriber('Meir', succeeded: 1, email: 'meir@example.com'); // below the bar
        [$product, $variant] = $this->giftProduct(price: 73.50);

        Livewire::test(GiftOrders::class)
            ->set('campaignTitle', '  Thank you, March  ')
            ->set('minCycles', 3)
            ->set('shippingLabel', 'Gift delivery')
            ->call('selectProduct', (int) $product->getKey())
            ->call('preview')
            ->call('generate')
            // The form clears so the next campaign starts from a blank rule rather
            // than silently reusing this one.
            ->assertSet('campaignTitle', '')
            ->assertSet('previewed', false);

        $campaign = GiftCampaign::query()->sole();
        $this->assertSame('Thank you, March', $campaign->title);
        $this->assertSame(3, (int) $campaign->min_cycles);
        $this->assertSame((int) $variant->getKey(), (int) $campaign->product_variant_id);
        // Snapshots: the catalog can be re-priced tomorrow; what was given away is
        // what it was worth today.
        $this->assertSame('73.50', number_format((float) $campaign->unit_price, 2, '.', ''));
        $this->assertSame('Gift delivery', $campaign->shipping_label);

        $recipient = GiftRecipient::query()->sole();
        $this->assertSame('Dana', $recipient->customer_name);
        $this->assertSame(4, (int) $recipient->cycles_at_generate);

        Queue::assertPushed(GiftOrderJob::class, 1);
    }

    public function test_only_a_rejected_recipient_can_be_retried_from_the_screen(): void
    {
        Queue::fake();
        $campaign = $this->campaign();
        $failed = $this->recipient($campaign, GiftRecipient::STATUS_FAILED, 1);
        $creating = $this->recipient($campaign, GiftRecipient::STATUS_CREATING, 2);

        $page = Livewire::test(GiftOrders::class);

        $page->call('retryRecipient', (int) $failed->getKey());
        $this->assertSame(GiftRecipient::STATUS_PENDING, $failed->fresh()->status);
        Queue::assertPushed(GiftOrderJob::class, 1);

        // An in-flight row may already have an order in the store — re-queueing it
        // is how a customer gets two packages.
        $page->call('retryRecipient', (int) $creating->getKey());
        $this->assertSame(GiftRecipient::STATUS_CREATING, $creating->fresh()->status);
        Queue::assertPushed(GiftOrderJob::class, 1);
    }

    public function test_another_shops_recipient_cannot_be_retried_here(): void
    {
        Queue::fake();

        $other = Shop::create([
            'woocommerce_domain' => 'gift-page-other.example.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $foreign = Tenant::run($other, function () use ($other): GiftRecipient {
            $campaign = $this->campaign($other);

            return $this->recipient($campaign, GiftRecipient::STATUS_FAILED, 1, $other);
        });

        Livewire::test(GiftOrders::class)->call('retryRecipient', (int) $foreign->getKey());

        // The global scope makes a foreign id resolve to nothing — a silent no-op,
        // never another merchant's order.
        $this->assertSame(GiftRecipient::STATUS_FAILED, GiftRecipient::withoutGlobalScopes()
            ->find($foreign->getKey())->status);
        Queue::assertNothingPushed();
    }

    // === Fixtures ===

    /** @return array{0: Product, 1: ProductVariant} */
    private function giftProduct(float $price): array
    {
        $product = new Product;
        $product->forceFill([
            'shop_id' => $this->shop->getKey(),
            'source' => Product::SOURCE_WOOCOMMERCE,
            'external_id' => '500',
            'title' => 'Gift Product',
            'status' => Product::STATUS_ACTIVE,
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'shop_id' => $this->shop->getKey(),
            'product_id' => $product->getKey(),
            'external_variant_id' => '500',
            'title' => 'Default',
            'price' => $price,
            'position' => 1,
        ])->save();

        return [$product->fresh(), $variant->fresh()];
    }

    private function campaign(?Shop $shop = null): GiftCampaign
    {
        $shop ??= $this->shop;

        $campaign = new GiftCampaign;
        $campaign->forceFill([
            'shop_id' => $shop->getKey(),
            'title' => 'Thanks',
            'min_cycles' => 3,
            'product_title' => 'Gift',
            'unit_price' => 10.00,
            'currency' => 'ILS',
            'status' => GiftCampaign::STATUS_GENERATING,
        ])->save();

        return $campaign;
    }

    private function recipient(GiftCampaign $campaign, string $status, int $sourceId, ?Shop $shop = null): GiftRecipient
    {
        $shop ??= $this->shop;

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

    private function subscriber(string $name, int $succeeded, string $email = 'dana@example.com'): InstallmentPlan
    {
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
        ]);
        $plan->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
        ])->save();

        for ($i = 1; $i <= $succeeded; $i++) {
            $payment = new InstallmentPayment;
            $payment->forceFill([
                'shop_id' => (int) $this->shop->getKey(),
                'plan_id' => $plan->getKey(),
                'payment_type' => PaymentType::RECURRING->value,
                'sequence' => $i,
                'amount' => 100,
                'currency' => 'ILS',
                'status' => PaymentStatus::SUCCEEDED->value,
            ])->save();
        }

        return $plan;
    }
}
