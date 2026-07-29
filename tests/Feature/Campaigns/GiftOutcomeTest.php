<?php

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\GiftAddressResolver;
use App\Domain\Campaigns\Jobs\GiftOrderJob;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a failure MEANS.
 *
 * Both platforms create the order first and run the merchant's plugins after, so a
 * fatal in somebody else's code produces a real order and a 500 describing it.
 * Reading that 500 as "the store refused" is how a merchant ends up shipping two
 * packages to the same person — the app offers a Retry, and the retry works.
 *
 * So: a 4xx is an ANSWER (nothing was created, retry is safe). A 5xx or a timeout
 * is not an answer at all — we go and look for the order, and if we cannot find it
 * nobody retries it automatically.
 */
final class GiftOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private GiftCampaign $campaign;
    private GiftRecipient $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'gift-outcome.example.com',
            'name' => 'Gift Outcome',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $this->shop->woocommerce_credentials = [
            'base_url' => 'https://gift-outcome.example.com',
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $this->shop->save();
        $this->shop = $this->shop->fresh();
        Tenant::set($this->shop);

        [$this->campaign, $this->recipient] = $this->fixture();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_rejected_order_is_failed_and_may_be_retried(): void
    {
        $this->fakeStore(createStatus: 400, createBody: ['code' => 'woocommerce_rest_invalid_product_id']);

        $recipient = $this->attempt();

        // A 400 is the store understanding the request and saying no. Nothing was
        // created, so a retry cannot duplicate anything.
        $this->assertSame(GiftRecipient::STATUS_FAILED, $recipient->status);
        $this->assertTrue($recipient->resetForRetry());
    }

    public function test_a_store_that_breaks_after_creating_the_order_records_the_order(): void
    {
        // Exactly the production failure: WooCommerce created order 2821, then a
        // plugin fatalled in the status hook and the request came back 500.
        $this->fakeStore(
            createStatus: 500,
            createBody: ['code' => 'internal_server_error'],
            recentOrders: [$this->orderRow(2821, recipientId: (int) $this->recipient->getKey())],
        );

        $recipient = $this->attempt();

        $this->assertSame(GiftRecipient::STATUS_CREATED, $recipient->status);
        $this->assertSame('2821', $recipient->external_order_id);
    }

    public function test_an_unfindable_order_is_never_offered_as_a_retry(): void
    {
        $this->fakeStore(createStatus: 500, createBody: ['code' => 'internal_server_error'], recentOrders: []);

        $recipient = $this->attempt();

        // We do not know whether it landed. Offering a Retry here is what ships a
        // second package, so the row waits for a human instead.
        $this->assertSame(GiftRecipient::STATUS_UNRESOLVED, $recipient->status);
        $this->assertSame(GiftRecipient::REASON_UNKNOWN_OUTCOME, $recipient->reason);
        $this->assertFalse($recipient->resetForRetry());
    }

    public function test_a_timeout_is_treated_as_unknown_not_as_a_refusal(): void
    {
        Http::fake([
            '*/wp-json/wc/v3/customers/*' => Http::response($this->customer(), 200),
            // 408: the request may well have been processed anyway.
            '*/wp-json/wc/v3/orders' => Http::sequence()
                ->push(['code' => 'timeout'], 408)      // the create
                ->push([], 200),                        // the reconciliation scan
            '*' => Http::response([], 200),
        ]);

        $recipient = $this->attempt();

        $this->assertSame(GiftRecipient::STATUS_UNRESOLVED, $recipient->status);
    }

    public function test_the_reconciliation_matches_on_the_recipient_not_the_campaign(): void
    {
        // Another recipient of the SAME campaign already has an order. Matching on
        // the campaign would hand this person their neighbour's order id.
        $this->fakeStore(
            createStatus: 500,
            createBody: ['code' => 'internal_server_error'],
            recentOrders: [$this->orderRow(9999, recipientId: 424242)],
        );

        $recipient = $this->attempt();

        $this->assertSame(GiftRecipient::STATUS_UNRESOLVED, $recipient->status);
        $this->assertNull($recipient->external_order_id);
    }

    public function test_the_reconcile_command_repairs_a_row_the_store_actually_has(): void
    {
        // The production case: the app said `failed` — which offers a Retry —
        // while the order was sitting in the store all along.
        $this->recipient->markFailed(GiftRecipient::REASON_API_ERROR);

        $this->fakeStore(
            createStatus: 500,
            createBody: [],
            recentOrders: [$this->orderRow(2821, recipientId: (int) $this->recipient->getKey())],
        );

        $this->artisan('gifts:reconcile')->assertSuccessful();

        $repaired = $this->recipient->fresh();
        $this->assertSame(GiftRecipient::STATUS_CREATED, $repaired->status);
        $this->assertSame('2821', $repaired->external_order_id);
        $this->assertFalse($repaired->resetForRetry(), 'a delivered gift is no longer retryable');
    }

    public function test_the_reconcile_command_invents_nothing_when_the_store_has_no_such_order(): void
    {
        $this->recipient->markFailed(GiftRecipient::REASON_API_ERROR);
        $this->fakeStore(createStatus: 500, createBody: [], recentOrders: []);

        $this->artisan('gifts:reconcile')->assertSuccessful();

        // Still failed, still retryable — the command only ever records an order it
        // has actually seen.
        $this->assertSame(GiftRecipient::STATUS_FAILED, $this->recipient->fresh()->status);
    }

    // === Fixtures ===

    /**
     * @param  array<string, mixed>  $createBody
     * @param  array<int, array<string, mixed>>|null  $recentOrders
     */
    private function fakeStore(int $createStatus, array $createBody, ?array $recentOrders = null): void
    {
        Http::fake([
            '*/wp-json/wc/v3/customers/*' => Http::response($this->customer(), 200),
            // The trailing * matters: the reconciliation scan carries a query
            // string, and a pattern without it silently never matches.
            '*/wp-json/wc/v3/orders*' => function ($request) use ($createStatus, $createBody, $recentOrders) {
                // Create and reconcile share a path; the METHOD separates them.
                return $request->method() === 'POST'
                    ? Http::response($createBody, $createStatus)
                    : Http::response($recentOrders ?? [], 200);
            },
            '*' => Http::response([], 200),
        ]);
    }

    /** @return array<string, mixed> */
    private function customer(): array
    {
        return [
            'id' => 55,
            'shipping' => ['first_name' => 'Dana', 'address_1' => 'Herzl 1', 'city' => 'Tel Aviv', 'country' => 'IL'],
        ];
    }

    /** @return array<string, mixed> */
    private function orderRow(int $orderId, int $recipientId): array
    {
        return [
            'id' => $orderId,
            'meta_data' => [
                ['key' => 'lets_order_role', 'value' => 'gift_order'],
                ['key' => 'lets_gift_recipient_id', 'value' => (string) $recipientId],
            ],
        ];
    }

    private function attempt(): GiftRecipient
    {
        (new GiftOrderJob(
            (int) $this->shop->getKey(),
            (int) $this->campaign->getKey(),
            (int) $this->recipient->getKey(),
        ))->handle(app(GiftAddressResolver::class));

        return $this->recipient->fresh();
    }

    /** @return array{0: GiftCampaign, 1: GiftRecipient} */
    private function fixture(): array
    {
        return Tenant::run($this->shop, function (): array {
            $product = new Product;
            $product->forceFill([
                'shop_id' => $this->shop->getKey(),
                'source' => Product::SOURCE_WOOCOMMERCE,
                'external_id' => '500',
                'title' => 'Gift',
                'status' => Product::STATUS_ACTIVE,
            ])->save();

            $variant = new ProductVariant;
            $variant->forceFill([
                'shop_id' => $this->shop->getKey(),
                'product_id' => $product->getKey(),
                'external_variant_id' => '500',
                'price' => 30.00,
                'position' => 1,
            ])->save();

            $plan = new InstallmentPlan;
            $plan->fill([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'total_amount' => 100,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'public_id' => (string) Str::ulid(),
                'customer_name' => 'Dana',
                'customer_email' => 'dana@example.com',
                'external_customer_id' => '55',
            ]);
            $plan->forceFill([
                'shop_id' => $this->shop->getKey(),
                'status' => PlanStatus::ACTIVE->value,
            ])->save();

            $campaign = new GiftCampaign;
            $campaign->forceFill([
                'shop_id' => $this->shop->getKey(),
                'title' => 'Thanks',
                'min_cycles' => 1,
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant->getKey(),
                'product_title' => 'Gift',
                'unit_price' => 30.00,
                'currency' => 'ILS',
                'shipping_label' => 'Gift shipping',
                'status' => GiftCampaign::STATUS_GENERATING,
            ])->save();

            $recipient = new GiftRecipient;
            $recipient->forceFill([
                'shop_id' => $this->shop->getKey(),
                'gift_campaign_id' => $campaign->getKey(),
                'source_type' => GiftRecipient::SOURCE_PLAN,
                'source_id' => $plan->getKey(),
                'customer_email' => 'dana@example.com',
                'status' => GiftRecipient::STATUS_PENDING,
                'currency' => 'ILS',
            ])->save();

            return [$campaign->fresh(), $recipient->fresh()];
        });
    }
}
