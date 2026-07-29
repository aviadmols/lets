<?php

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\GiftCampaignGenerator;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A thousand subscribers.
 *
 * A gift campaign is the only place in this app where one click creates a thousand
 * orders. Three things break at that size if nobody bounds them: the store, under
 * a thousand writes arriving at queue speed; the screen, painting a thousand rows
 * per campaign; and the queries behind the screen, run once per campaign row.
 *
 * These pin the bounds. They are about SHAPE — a fixed number of queries, a
 * bounded amount of HTML, a dispatch spread over time — not about milliseconds.
 */
final class GiftCampaignScaleTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    /** Enough to prove the bounds hold without a slow fixture. */
    private const SUBSCRIBERS = 250;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'gift-scale.example.com',
            'name' => 'Gift Scale',
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

    public function test_every_subscriber_is_enrolled_exactly_once(): void
    {
        Queue::fake();
        $this->subscribers(self::SUBSCRIBERS);
        $campaign = $this->campaign();

        $result = app(GiftCampaignGenerator::class)->generate($this->shop, $campaign);

        $this->assertSame(self::SUBSCRIBERS, $result['enrolled']);
        $this->assertSame(self::SUBSCRIBERS, GiftRecipient::query()->count());
        Queue::assertPushed(GiftOrderJob::class, self::SUBSCRIBERS);

        // And a re-run of the same campaign adds nobody — the unique index holds
        // at size, which is the whole duplicate wall.
        $second = app(GiftCampaignGenerator::class)->generate($this->shop, $campaign->fresh());
        $this->assertSame(0, $second['enrolled']);
        $this->assertSame(self::SUBSCRIBERS, GiftRecipient::query()->count());
    }

    public function test_the_orders_are_spread_over_time_not_fired_at_once(): void
    {
        Queue::fake();
        $this->subscribers(self::SUBSCRIBERS);

        app(GiftCampaignGenerator::class)->generate($this->shop, $this->campaign());

        $delays = [];
        Queue::assertPushed(GiftOrderJob::class, function (GiftOrderJob $job) use (&$delays): bool {
            $delays[] = $job->delay;

            return true;
        });

        // The first goes now; the last waits. A small WooCommerce host serving a
        // thousand order writes at queue speed starts timing out, and a timeout is
        // an unknown outcome — the expensive kind.
        $now = now()->getTimestamp();
        $seconds = array_map(fn ($d): int => $d === null ? 0 : $d->getTimestamp() - $now, $delays);

        $this->assertNotEmpty(array_filter($seconds, fn (int $s): bool => $s > 0));
        $this->assertSame(
            (self::SUBSCRIBERS - 1) * GiftCampaignGenerator::SECONDS_BETWEEN_ORDERS,
            max($seconds),
            'the last recipient is paced to the end of the window',
        );
    }

    public function test_the_preview_paints_a_bounded_page_but_counts_everyone(): void
    {
        $this->subscribers(self::SUBSCRIBERS);

        $page = Livewire::test(GiftOrders::class)->set('minCycles', 1)->call('preview');

        // The count is exact...
        $this->assertCount(self::SUBSCRIBERS, $page->instance()->qualifying());
        // ...and the merchant is told the table is a window onto it.
        $page->assertSee(__('gifts.preview_more', [
            'shown' => GiftOrders::PREVIEW_ROWS,
            'total' => self::SUBSCRIBERS,
        ]));
    }

    public function test_a_campaign_of_thousands_does_not_paint_a_row_each(): void
    {
        $this->subscribers(self::SUBSCRIBERS);
        $campaign = $this->campaign();
        $this->enrol($campaign, GiftRecipient::STATUS_CREATED, self::SUBSCRIBERS);

        $html = Livewire::test(GiftOrders::class)->html();

        // Delivered gifts are a COUNT, not rows: nobody scrolls 250 lines that all
        // say "Sent".
        $this->assertStringContainsString(__('gifts.recipient_status.created'), $html);
        $this->assertLessThan(
            self::SUBSCRIBERS,
            substr_count($html, 'rcpt-'),
            'the campaign history must not render one row per delivered gift',
        );
    }

    public function test_the_screen_costs_the_same_whether_a_campaign_has_ten_recipients_or_thousands(): void
    {
        $this->subscribers(self::SUBSCRIBERS);

        $small = $this->campaign('Small');
        $this->enrol($small, GiftRecipient::STATUS_CREATED, 10);
        $cheap = $this->queriesToRender();

        $big = $this->campaign('Big');
        $this->enrol($big, GiftRecipient::STATUS_CREATED, self::SUBSCRIBERS, startAt: 1000);
        $expensive = $this->queriesToRender();

        // The counts are one grouped query for the whole page, and delivered gifts
        // are never listed — so twenty-five times the recipients costs the same as
        // one more campaign, not one more query per gift.
        $this->assertSame(
            $expensive - $cheap,
            $this->queriesPerCampaign(),
            "rendering grew from {$cheap} to {$expensive} queries",
        );
    }

    private function queriesToRender(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(GiftOrders::class)->html();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * One extra campaign in the list costs one extra "who needs attention?" query —
     * bounded by CAMPAIGN_LIMIT, and independent of how many recipients each holds.
     */
    private function queriesPerCampaign(): int
    {
        return 1;
    }

    // === Fixtures ===

    private function subscribers(int $count): void
    {
        $now = now();
        $plans = [];
        for ($i = 1; $i <= $count; $i++) {
            $plans[] = [
                'shop_id' => $this->shop->getKey(),
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'status' => PlanStatus::ACTIVE->value,
                'total_amount' => 100,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'public_id' => (string) Str::ulid(),
                'customer_name' => 'Subscriber '.$i,
                'customer_email' => 'sub'.$i.'@example.com',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        InstallmentPlan::query()->insert($plans);

        $payments = [];
        foreach (InstallmentPlan::query()->pluck('id') as $planId) {
            $payments[] = [
                'shop_id' => $this->shop->getKey(),
                'plan_id' => $planId,
                'payment_type' => PaymentType::RECURRING->value,
                'sequence' => 1,
                'amount' => 100,
                'currency' => 'ILS',
                'status' => PaymentStatus::SUCCEEDED->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        InstallmentPayment::query()->insert($payments);
    }

    private function campaign(string $title = 'Thanks'): GiftCampaign
    {
        // One catalog product, many campaigns — the external id is unique per shop.
        $product = Product::query()->firstOrCreate(
            ['source' => Product::SOURCE_WOOCOMMERCE, 'external_id' => '500'],
            ['title' => 'Gift', 'status' => Product::STATUS_ACTIVE],
        );

        $variant = ProductVariant::query()->firstOrCreate(
            ['product_id' => $product->getKey(), 'external_variant_id' => '500'],
            ['price' => 25.00, 'position' => 1],
        );

        $campaign = new GiftCampaign;
        $campaign->forceFill([
            'shop_id' => $this->shop->getKey(),
            'title' => $title,
            'min_cycles' => 1,
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'product_title' => 'Gift',
            'unit_price' => 25.00,
            'currency' => 'ILS',
            'shipping_label' => 'Gift shipping',
            'status' => GiftCampaign::STATUS_GENERATING,
        ])->save();

        return $campaign->fresh();
    }

    private function enrol(GiftCampaign $campaign, string $status, int $count, int $startAt = 1): void
    {
        $now = now();
        $rows = [];
        for ($i = $startAt; $i < $startAt + $count; $i++) {
            $rows[] = [
                'shop_id' => $this->shop->getKey(),
                'gift_campaign_id' => $campaign->getKey(),
                'source_type' => GiftRecipient::SOURCE_PLAN,
                'source_id' => $i,
                'customer_name' => 'Subscriber '.$i,
                'status' => $status,
                'external_order_id' => (string) (9000 + $i),
                'currency' => 'ILS',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        GiftRecipient::query()->insert($rows);
    }
}
