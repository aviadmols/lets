<?php

namespace Tests\Feature\WooCommerce;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\ChargeContext;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Orders\PlatformOrderStrategy;
use App\Services\Orders\PlatformOrderStrategyFactory;
use App\Services\WooCommerce\Orders\WooCommerceOrderStrategy;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W11 P3 — the WooCommerce order strategy materializes WC store state per charge_context
 * via the WC REST API, AFTER a succeeded ledger row. Driven directly here (the
 * orchestrator integration is covered separately). WC REST is Http::faked; we assert the
 * exact create/update calls. Idempotent on the stored external order id; fail-soft on an
 * unconnected shop.
 */
final class WooCommerceOrderStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PlatformOrderStrategyFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_factory_routes_a_woocommerce_shop_to_this_strategy(): void
    {
        $strategy = PlatformOrderStrategyFactory::for(new Shop(['platform' => Shop::PLATFORM_WOOCOMMERCE]));

        $this->assertInstanceOf(WooCommerceOrderStrategy::class, $strategy);
        $this->assertInstanceOf(PlatformOrderStrategy::class, $strategy);
    }

    public function test_deposit_creates_a_locked_parent_order_and_stores_its_id(): void
    {
        $shop = $this->connectedShop('deposit-strat.example.com');
        Http::fake(['*/wp-json/wc/v3/orders' => Http::response(['id' => 4242], 201)]);

        $plan = $this->plan($shop, PlanKind::INSTALLMENTS, [
            'public_id' => 'PUB-DEP', 'total_amount' => 400, 'total_charged' => 100,
            'external_variant_id' => '200', 'external_product_id' => '100',
        ]);

        Tenant::run($shop, fn () => (new WooCommerceOrderStrategy())->materialize($plan, ChargeContext::DEPOSIT));

        Http::assertSent(function (Request $req) {
            $b = $req->data();
            return str_contains($req->url(), '/wp-json/wc/v3/orders')
                && $req->method() === 'POST'
                && $b['status'] === 'processing'           // LOCKED, not completed
                && $b['set_paid'] === false
                && $this->metaValue($b, 'lets_plan_public_id') === 'PUB-DEP'
                && $this->metaValue($b, 'lets_order_role') === 'main_order'
                && (int) $b['line_items'][0]['variation_id'] === 200;
        });

        $plan->refresh();
        $this->assertSame('4242', (string) $plan->external_order_id);
    }

    public function test_deposit_is_idempotent_when_a_parent_order_already_exists(): void
    {
        $shop = $this->connectedShop('idem-strat.example.com');
        Http::fake(['*/wp-json/wc/v3/orders' => Http::response(['id' => 1], 201)]);

        $plan = $this->plan($shop, PlanKind::INSTALLMENTS, ['public_id' => 'PUB-IDEM', 'external_order_id' => '999']);

        Tenant::run($shop, fn () => (new WooCommerceOrderStrategy())->materialize($plan, ChargeContext::DEPOSIT));

        // Already has an order id → no WC call.
        Http::assertNothingSent();
    }

    public function test_installment_updates_the_parent_meta_without_completing(): void
    {
        $shop = $this->connectedShop('inst-strat.example.com');
        Http::fake(['*/wp-json/wc/v3/orders/55' => Http::response(['id' => 55], 200)]);

        $plan = $this->plan($shop, PlanKind::INSTALLMENTS, [
            'public_id' => 'PUB-INST', 'external_order_id' => '55',
            'total_amount' => 400, 'total_charged' => 200,
        ]);

        Tenant::run($shop, fn () => (new WooCommerceOrderStrategy())->materialize($plan, ChargeContext::INSTALLMENT, isFinal: false));

        Http::assertSent(function (Request $req) {
            $b = $req->data();
            return str_contains($req->url(), '/wp-json/wc/v3/orders/55')
                && $req->method() === 'PUT'
                && ! isset($b['status'])                       // NOT completed mid-plan
                && $this->metaValue($b, 'lets_installment_status') === 'active'
                && $this->metaValue($b, 'lets_remaining_balance') === '200.00';
        });
    }

    public function test_final_installment_completes_the_parent_order(): void
    {
        $shop = $this->connectedShop('final-strat.example.com');
        Http::fake(['*/wp-json/wc/v3/orders/77' => Http::response(['id' => 77], 200)]);

        // Fully paid (remaining ~0) so isFullyPaid() is true on the final slice.
        $plan = $this->plan($shop, PlanKind::INSTALLMENTS, [
            'public_id' => 'PUB-FINAL', 'external_order_id' => '77',
            'total_amount' => 400, 'total_charged' => 400,
        ]);

        Tenant::run($shop, fn () => (new WooCommerceOrderStrategy())->materialize($plan, ChargeContext::INSTALLMENT, isFinal: true));

        Http::assertSent(function (Request $req) {
            $b = $req->data();
            return str_contains($req->url(), '/wp-json/wc/v3/orders/77')
                && $req->method() === 'PUT'
                && ($b['status'] ?? null) === 'completed'       // fulfillment released
                && $this->metaValue($b, 'lets_installment_status') === 'completed';
        });
    }

    public function test_recurring_creates_a_new_paid_order_per_cycle(): void
    {
        $shop = $this->connectedShop('rec-strat.example.com');
        Http::fake(['*/wp-json/wc/v3/orders' => Http::response(['id' => 8001], 201)]);

        $plan = $this->plan($shop, PlanKind::RECURRING, [
            'public_id' => 'PUB-REC', 'installment_amount' => 49.90, 'external_order_id' => '500',
        ]);

        Tenant::run($shop, fn () => (new WooCommerceOrderStrategy())->materialize($plan, ChargeContext::RECURRING));

        Http::assertSent(function (Request $req) {
            $b = $req->data();
            return str_contains($req->url(), '/wp-json/wc/v3/orders')
                && $req->method() === 'POST'
                && ($b['status'] ?? null) === 'completed'
                && ($b['set_paid'] ?? null) === true
                && $this->metaValue($b, 'lets_order_role') === 'recurring_order'
                && $this->metaValue($b, 'lets_main_order_id') === '500'
                && $b['line_items'][0]['total'] === '49.90';
        });

        // The cycle order id is recorded on the plan meta (idempotency/reconciliation).
        $plan->refresh();
        $this->assertContains('8001', (array) data_get($plan->meta, 'wc_recurring_order_ids'));
    }

    public function test_upsell_context_is_a_noop(): void
    {
        $shop = $this->connectedShop('upsell-strat.example.com');
        Http::fake();

        $plan = $this->plan($shop, PlanKind::RECURRING, ['public_id' => 'PUB-UP', 'installment_amount' => 10]);

        Tenant::run($shop, fn () => (new WooCommerceOrderStrategy())->materialize($plan, ChargeContext::UPSELL));

        Http::assertNothingSent();
    }

    public function test_an_unconnected_shop_is_skipped_without_calling_wc(): void
    {
        // A WC shop with NO REST creds (handshake never completed).
        $shop = Shop::create([
            'woocommerce_domain' => 'noconn.example.com', 'name' => 'No Conn',
            'status' => Shop::STATUS_INSTALLED, 'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Http::fake();

        $plan = $this->plan($shop, PlanKind::INSTALLMENTS, ['public_id' => 'PUB-NC', 'total_amount' => 100]);

        Tenant::run($shop, fn () => (new WooCommerceOrderStrategy())->materialize($plan, ChargeContext::DEPOSIT));

        Http::assertNothingSent();
    }

    // === Helpers ===

    private function connectedShop(string $domain): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => $domain, 'name' => $domain,
            'status' => Shop::STATUS_INSTALLED, 'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->woocommerce_credentials = [
            'base_url' => 'https://'.$domain, 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $shop->save();

        return $shop->fresh();
    }

    /** @param array<string,mixed> $attrs */
    private function plan(Shop $shop, PlanKind $kind, array $attrs): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $kind, $attrs): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill(array_merge([
                'plan_kind' => $kind->value,
                'charge_context' => $kind === PlanKind::RECURRING ? 'recurring' : 'deposit',
                'total_amount' => 100,
                'total_charged' => 0,
                'installment_amount' => 50,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'customer_email' => 'shopper@example.com',
                'customer_name' => 'Shopper',
                'meta' => [],
            ], $attrs));
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => PlanStatus::ACTIVE->value,
            ])->save();

            return $plan->fresh();
        });
    }

    /** Read a WC meta_data value by key from a request body. */
    private function metaValue(array $body, string $key): mixed
    {
        foreach ((array) ($body['meta_data'] ?? []) as $meta) {
            if (($meta['key'] ?? null) === $key) {
                return $meta['value'] ?? null;
            }
        }

        return null;
    }
}
