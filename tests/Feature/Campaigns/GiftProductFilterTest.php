<?php

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\GiftEligibility;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Filament\Pages\GiftOrders;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
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
 * "Everyone past N cycles" is one rule. "Everyone past N cycles who subscribes to
 * the coffee box" is the one a merchant wants when the gift is a coffee mug.
 *
 * The filter is an OR across the chosen products — subscribing to any one of them
 * qualifies — and it fails CLOSED everywhere it cannot answer: a subscription with
 * no product recorded is not swept in on the assumption that it might match.
 */
final class GiftProductFilterTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'gift-filter.example.com',
            'name' => 'Gift Filter',
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

    public function test_no_products_chosen_means_every_subscriber(): void
    {
        $this->product('600', 'Coffee');
        $this->product('700', 'Tea');
        $this->subscriber('Coffee drinker', '600');
        $this->subscriber('Tea drinker', '700', 'tea@example.com');

        $rows = app(GiftEligibility::class)->qualifying(1);

        $this->assertCount(2, $rows);
    }

    public function test_choosing_one_product_excludes_the_other_products_subscribers(): void
    {
        $coffee = $this->product('600', 'Coffee');
        $this->product('700', 'Tea');
        $this->subscriber('Coffee drinker', '600');
        $this->subscriber('Tea drinker', '700', 'tea@example.com');

        $rows = app(GiftEligibility::class)->qualifying(1, null, [(int) $coffee->getKey()]);

        $this->assertCount(1, $rows);
        $this->assertSame('Coffee drinker', $rows->first()['label']);
    }

    public function test_several_products_are_an_or_not_an_and(): void
    {
        $coffee = $this->product('600', 'Coffee');
        $tea = $this->product('700', 'Tea');
        $this->product('800', 'Cocoa');
        $this->subscriber('Coffee drinker', '600');
        $this->subscriber('Tea drinker', '700', 'tea@example.com');
        $this->subscriber('Cocoa drinker', '800', 'cocoa@example.com');

        $rows = app(GiftEligibility::class)->qualifying(1, null, [
            (int) $coffee->getKey(), (int) $tea->getKey(),
        ]);

        // Subscribing to ANY of them qualifies — nobody subscribes to two things at
        // once, so an AND would match nobody at all.
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            ['Coffee drinker', 'Tea drinker'],
            $rows->pluck('label')->all(),
        );
    }

    public function test_the_cycle_threshold_still_applies_alongside_the_filter(): void
    {
        $coffee = $this->product('600', 'Coffee');
        $this->subscriber('Loyal', '600', cycles: 4);
        $this->subscriber('Newcomer', '600', 'new@example.com', cycles: 1);

        $rows = app(GiftEligibility::class)->qualifying(3, null, [(int) $coffee->getKey()]);

        // The filter narrows; it must not replace the threshold. An OR that escaped
        // its group would return the newcomer too.
        $this->assertCount(1, $rows);
        $this->assertSame('Loyal', $rows->first()['label']);
    }

    public function test_a_subscription_with_no_product_recorded_is_not_swept_in(): void
    {
        $coffee = $this->product('600', 'Coffee');
        $this->subscriber('Unknown product', null, 'unknown@example.com');

        $rows = app(GiftEligibility::class)->qualifying(1, null, [(int) $coffee->getKey()]);

        // We cannot say they subscribe to the coffee box, so we do not say it.
        $this->assertCount(0, $rows);
    }

    public function test_another_shops_product_id_selects_nobody(): void
    {
        $this->product('600', 'Coffee');
        $this->subscriber('Coffee drinker', '600');

        $other = Shop::create([
            'woocommerce_domain' => 'gift-filter-other.example.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $foreign = Tenant::run($other, fn () => $this->product('600', 'Their coffee', $other));

        $rows = app(GiftEligibility::class)->qualifying(1, null, [(int) $foreign->getKey()]);

        // A filter that resolved to nothing must select NOBODY. Falling back to
        // "everyone" would gift the whole subscriber base off a rule that quietly
        // evaporated.
        $this->assertCount(0, $rows);
    }

    public function test_a_shopify_contract_is_matched_on_its_line_product(): void
    {
        $coffee = $this->product('600', 'Coffee');
        $this->contract('Contract coffee', productGid: 'gid://shopify/Product/600');
        $this->contract('Contract tea', productGid: 'gid://shopify/Product/700', email: 'tea@example.com');

        $rows = app(GiftEligibility::class)->qualifying(1, null, [(int) $coffee->getKey()]);

        $this->assertCount(1, $rows);
        $this->assertSame('Contract coffee', $rows->first()['label']);
    }

    public function test_a_contract_mirrored_before_product_ids_were_stored_is_excluded(): void
    {
        $coffee = $this->product('600', 'Coffee');
        $this->contract('Old mirror', productGid: null);

        $rows = app(GiftEligibility::class)->qualifying(1, null, [(int) $coffee->getKey()]);

        // Its lines carry a title and nothing else. Two products can share a title,
        // so guessing here would gift the wrong people.
        $this->assertCount(0, $rows);
    }

    public function test_the_screen_saves_and_reloads_the_chosen_products(): void
    {
        Queue::fake();
        $coffee = $this->product('600', 'Coffee');
        $gift = $this->product('900', 'Mug');
        $this->giftVariant($gift);
        $this->subscriber('Coffee drinker', '600');

        Livewire::test(GiftOrders::class)
            ->set('campaignTitle', 'Coffee thanks')
            ->set('minCycles', 1)
            ->call('addSourceProduct', (int) $coffee->getKey())
            ->call('addGiftItem', (int) $gift->getKey())
            ->call('save');

        $campaign = GiftCampaign::query()->sole();
        $this->assertSame([(int) $coffee->getKey()], $campaign->sourceProductIds());

        // Reopening the draft brings the rule back intact — otherwise saving it
        // again would silently widen the campaign to everyone.
        Livewire::test(GiftOrders::class)
            ->call('editCampaign', (int) $campaign->getKey())
            ->assertSet('sourceProductIds', [(int) $coffee->getKey()]);
    }

    public function test_a_saved_campaign_generates_only_its_products_subscribers(): void
    {
        Queue::fake();
        $coffee = $this->product('600', 'Coffee');
        $gift = $this->product('900', 'Mug');
        $this->giftVariant($gift);
        $this->subscriber('Coffee drinker', '600');
        $this->subscriber('Tea drinker', '700', 'tea@example.com');

        Livewire::test(GiftOrders::class)
            ->set('campaignTitle', 'Coffee thanks')
            ->set('minCycles', 1)
            ->call('addSourceProduct', (int) $coffee->getKey())
            ->call('addGiftItem', (int) $gift->getKey())
            ->call('preview')
            ->call('generate');

        $recipients = GiftRecipient::query()->get();

        // The generator reads the rule off the SAVED campaign, so what was
        // previewed is what goes out.
        $this->assertCount(1, $recipients);
        $this->assertSame('Coffee drinker', $recipients->first()->customer_name);
    }

    // === Fixtures ===

    private function product(string $externalId, string $title, ?Shop $shop = null): Product
    {
        $shop ??= $this->shop;

        $product = new Product;
        $product->forceFill([
            'shop_id' => $shop->getKey(),
            'source' => Product::SOURCE_WOOCOMMERCE,
            'external_id' => $externalId,
            'title' => $title,
            'status' => Product::STATUS_ACTIVE,
        ])->save();

        return $product->fresh();
    }

    private function giftVariant(Product $product): void
    {
        $variant = new ProductVariant;
        $variant->forceFill([
            'shop_id' => $this->shop->getKey(),
            'product_id' => $product->getKey(),
            'external_variant_id' => $product->external_id,
            'price' => 25.00,
            'position' => 1,
        ])->save();
    }

    private function subscriber(string $name, ?string $externalProductId, string $email = 'dana@example.com', int $cycles = 2): void
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
            'external_product_id' => $externalProductId,
        ]);
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
        ])->save();

        for ($i = 1; $i <= $cycles; $i++) {
            $payment = new InstallmentPayment;
            $payment->forceFill([
                'shop_id' => $this->shop->getKey(),
                'plan_id' => $plan->getKey(),
                'payment_type' => PaymentType::RECURRING->value,
                'sequence' => $i,
                'amount' => 100,
                'currency' => 'ILS',
                'status' => PaymentStatus::SUCCEEDED->value,
            ])->save();
        }
    }

    private function contract(string $name, ?string $productGid, string $email = 'dana@example.com'): void
    {
        $contract = new SubscriptionContract;
        $contract->forceFill([
            'shop_id' => $this->shop->getKey(),
            'shopify_gid' => 'gid://shopify/SubscriptionContract/'.Str::random(8),
            'status' => SubscriptionContract::STATUS_ACTIVE,
            'currency' => 'ILS',
            'customer_name' => $name,
            'customer_email' => $email,
            'lines' => [[
                'title' => 'A subscription',
                'quantity' => 1,
                'amount' => '50.00',
                'product_id' => $productGid,
            ]],
        ])->save();

        $attempt = new SubscriptionBillingAttempt;
        $attempt->forceFill([
            'shop_id' => $this->shop->getKey(),
            'subscription_contract_id' => $contract->getKey(),
            'status' => SubscriptionBillingAttempt::STATUS_SUCCEEDED,
            'billing_cycle_key' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
        ])->save();
    }
}
