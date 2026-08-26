<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\InstallmentPlan;
use App\Models\MerchantPortalAppearance;
use App\Models\Product;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Gifts from us" in the personal area — the shopper's own view of the gift
 * campaigns.
 *
 * Three rules: only gifts that actually SHIPPED (`created`) appear; only THIS
 * shopper's (matched on the platform-asserted email, case-insensitively); and
 * the whole section is the merchant's to switch off.
 */
final class GiftsSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_shipped_gifts_appear_with_title_image_and_date(): void
    {
        $shop = $this->shop('gifts-list.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->plan($shop, 'g1', 'dana@example.com');
            $campaign = $this->campaign($shop, 'Winter mug', 'https://cdn.example.com/mug.png');

            $this->recipient($shop, $campaign, 'DANA@example.com', GiftRecipient::STATUS_CREATED);
            // Never shipped — must not be promised.
            $this->recipient($shop, $campaign, 'dana@example.com', GiftRecipient::STATUS_SKIPPED);
            // Somebody else's gift.
            $this->recipient($shop, $campaign, 'other@example.com', GiftRecipient::STATUS_CREATED);

            $model = app(AccountPresenter::class)->present($this->visitor($shop, 'g1', 'dana@example.com'));

            $this->assertCount(1, $model['gifts']);
            $gift = $model['gifts'][0];
            $this->assertSame('Winter mug', $gift['title']);
            $this->assertSame('https://cdn.example.com/mug.png', $gift['image']);
            $this->assertSame(now()->toDateString(), $gift['sent_at']);

            // The section key + its copy ride the payload for the renderer.
            $this->assertContains(MerchantPortalAppearance::SECTION_GIFTS, $model['sections']);
            $this->assertArrayHasKey('gifts_heading', $model['copy']);
            $this->assertArrayHasKey('gift_sent_on', $model['copy']);
        });
    }

    public function test_the_merchant_names_the_shelf_and_its_tab(): void
    {
        $shop = $this->shop('gifts-named.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->plan($shop, 'g4', 'dana@example.com');

            // Untouched: the shelf keeps the full default sentence, but the TAB
            // gets the one-word default — a nav label is not a heading.
            $model = app(AccountPresenter::class)->present($this->visitor($shop, 'g4', 'dana@example.com'));
            $this->assertSame(__('account.ui.gifts_heading'), $model['copy']['gifts_heading']);
            $this->assertSame(__('account.ui.gifts_tab'), $model['copy']['gifts_tab_label']);

            // The merchant's own wording wins in BOTH places at once.
            $appearance = MerchantPortalAppearance::current();
            $appearance->gifts_heading = 'הספרים שקיבלתם במתנה';
            $appearance->save();

            $model = app(AccountPresenter::class)->present($this->visitor($shop, 'g4', 'dana@example.com'));
            $this->assertSame('הספרים שקיבלתם במתנה', $model['copy']['gifts_heading']);
            $this->assertSame('הספרים שקיבלתם במתנה', $model['copy']['gifts_tab_label']);
        });
    }

    public function test_the_merchant_can_switch_the_section_off(): void
    {
        $shop = $this->shop('gifts-off.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->plan($shop, 'g2', 'dana@example.com');
            $campaign = $this->campaign($shop, 'Winter mug', null);
            $this->recipient($shop, $campaign, 'dana@example.com', GiftRecipient::STATUS_CREATED);

            $appearance = MerchantPortalAppearance::current();
            $appearance->sections = array_map(
                static fn (string $key): array => ['key' => $key, 'enabled' => $key !== MerchantPortalAppearance::SECTION_GIFTS],
                MerchantPortalAppearance::SECTION_KEYS,
            );
            $appearance->save();

            $model = app(AccountPresenter::class)->present($this->visitor($shop, 'g2', 'dana@example.com'));

            $this->assertNotContains(MerchantPortalAppearance::SECTION_GIFTS, $model['sections']);
            $this->assertSame([], $model['gifts'], 'a hidden section ships no data either');
        });
    }

    public function test_another_shops_gifts_never_leak(): void
    {
        $mine = $this->shop('gifts-mine.example.com');
        $theirs = $this->shop('gifts-theirs.example.com');

        Tenant::run($theirs, function () use ($theirs): void {
            $campaign = $this->campaign($theirs, 'Their mug', null);
            $this->recipient($theirs, $campaign, 'dana@example.com', GiftRecipient::STATUS_CREATED);
        });

        Tenant::run($mine, function () use ($mine): void {
            $this->plan($mine, 'g3', 'dana@example.com');

            $model = app(AccountPresenter::class)->present($this->visitor($mine, 'g3', 'dana@example.com'));

            $this->assertSame([], $model['gifts']);
        });
    }

    // === helpers ===

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    private function campaign(Shop $shop, string $productTitle, ?string $imageUrl): GiftCampaign
    {
        $product = Product::create([
            'source' => Product::SOURCE_WOOCOMMERCE,
            'external_id' => 'gift-'.uniqid(),
            'title' => $productTitle,
            'image_url' => $imageUrl,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $campaign = new GiftCampaign;
        $campaign->forceFill([
            'shop_id' => $shop->getKey(),
            'title' => 'Loyalty gifts',
            'min_cycles' => 1,
            'product_id' => $product->getKey(),
            'product_title' => $productTitle,
            'unit_price' => 25,
            'currency' => 'ILS',
            'status' => GiftCampaign::STATUS_COMPLETED,
        ])->save();

        return $campaign;
    }

    private function recipient(Shop $shop, GiftCampaign $campaign, string $email, string $status): GiftRecipient
    {
        $recipient = new GiftRecipient;
        $recipient->forceFill([
            'shop_id' => $shop->getKey(),
            'gift_campaign_id' => $campaign->getKey(),
            'source_type' => GiftRecipient::SOURCE_PLAN,
            'source_id' => random_int(1, 999999),
            'customer_name' => 'Dana',
            'customer_email' => $email,
            'cycles_at_generate' => 3,
            'status' => $status,
            'currency' => 'ILS',
        ])->save();

        return $recipient;
    }

    private function visitor(Shop $shop, string $ref, string $email): AccountVisitor
    {
        return AccountVisitor::make(
            shop: $shop,
            customerRef: $ref,
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
            email: $email,
        );
    }

    private function plan(Shop $shop, string $ref, string $email): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $shop->getKey(),
            'public_id' => 'PLN-'.$ref.'-'.uniqid(),
            'external_customer_id' => $ref,
            'customer_email' => $email,
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 59,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
        ])->save();

        return $plan;
    }
}
