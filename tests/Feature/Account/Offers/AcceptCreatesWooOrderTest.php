<?php

namespace Tests\Feature\Account\Offers;

use App\Domain\Account\AccountVisitor;
use App\Domain\Account\Offers\AccountOfferAcceptService;
use App\Domain\Account\Offers\AccountOfferOutcome;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The merchant's books live in WooCommerce: a subscription switch that charges
 * money MUST leave a WC order behind, exactly like every scheduled cycle. This
 * was the uncovered seam — the accept tests proved the ledger and consent, the
 * strategy tests proved order-building, and nothing proved the two met.
 */
final class AcceptCreatesWooOrderTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public array $wooOrders = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->wooOrders = [];

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class implements PayPlusGatewayInterface
        {
            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-1']],
                ]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });

        // NOTE: Http::fake is per-test (not here) — stubs registered first win in
        // Laravel, so a setUp-level success stub would silently override a test's
        // failure stub and the failure test would "pass" the wrong way.
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_an_immediate_switch_creates_a_paid_wc_cycle_order(): void
    {
        $test = $this;
        Http::fake([
            '*/wp-json/wc/v3/orders' => function ($request) use ($test) {
                $test->wooOrders[] = (array) $request->data();

                return Http::response(['id' => 9000 + count($test->wooOrders)], 201);
            },
            '*' => Http::response(['id' => 1], 200),
        ]);

        $shop = $this->makeShop('accept-order.example.com');
        $shop->forceFill([
            'woocommerce_domain' => 'wc.example.com',
            'woocommerce_credentials' => [
                'base_url' => 'https://wc.example.com',
                'consumer_key' => 'ck_x',
                'consumer_secret' => 'cs_x',
            ],
        ])->save();
        $shop = $shop->refresh();

        [$offer, $source, $targetKey] = Tenant::run($shop, function (): array {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $offer = $this->makeOffer();
            $target = $this->addTarget($offer, [
                'product_subscription_plan_id' => $this->makeTemplate($product, BillingFrequency::MONTHLY)->getKey(),
            ]);

            return [$offer, $this->makeSourcePlan(), $target->stableKey()];
        });

        $outcome = Tenant::run($shop, fn (): AccountOfferOutcome => app(AccountOfferAcceptService::class)->accept(
            AccountVisitor::make(
                shop: $shop,
                customerRef: self::MEMBER_REF,
                source: AccountVisitor::SOURCE_WOOCOMMERCE,
                email: self::MEMBER_EMAIL,
            ),
            $source->fresh(),
            (string) $offer->getKey(),
            $targetKey,
            49.0,
        ));

        $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);

        // The money moved — the merchant's store must say so too: one PAID WC
        // order for the first cycle, linked to the NEW plan.
        $new = Tenant::run($shop, fn () => InstallmentPlan::query()
            ->where('public_id', '!=', $source->public_id)->latest('id')->first());

        $this->assertNotEmpty($this->wooOrders, 'the switch charged money but created no WC order');
        $order = end($this->wooOrders);
        $this->assertTrue((bool) ($order['set_paid'] ?? false));
        $this->assertSame(
            (string) $new->public_id,
            collect((array) ($order['meta_data'] ?? []))->firstWhere('key', 'lets_plan_public_id')['value'] ?? null,
        );

        // The line must carry a REAL product reference: modern WooCommerce
        // refuses a name-only line (`woocommerce_rest_required_product_reference`)
        // — the exact 400 that silently ate every cycle order on the pilot store.
        $line = (array) (((array) ($order['line_items'] ?? []))[0] ?? []);
        $this->assertSame((int) self::PRODUCT_MONTHLY, (int) ($line['product_id'] ?? 0));
        $this->assertArrayNotHasKey('variation_id', $line, 'W23: no variation echoing a simple product');
    }

    /**
     * When the store refuses the order, the money still moved — and the merchant
     * must SEE the gap. This exact failure ate every cycle order on the pilot
     * store for weeks, visible only in a log that rotates.
     */
    public function test_a_store_that_refuses_the_order_leaves_a_flare_on_the_timeline(): void
    {
        Http::fake([
            '*/wp-json/wc/v3/orders' => Http::response([
                'code' => 'woocommerce_rest_required_product_reference',
                'message' => 'A product ID or SKU is required',
            ], 400),
            '*' => Http::response(['id' => 1], 200),
        ]);

        $shop = $this->makeShop('accept-order-fail.example.com');
        $shop->forceFill([
            'woocommerce_domain' => 'wc.example.com',
            'woocommerce_credentials' => [
                'base_url' => 'https://wc.example.com',
                'consumer_key' => 'ck_x',
                'consumer_secret' => 'cs_x',
            ],
        ])->save();
        $shop = $shop->refresh();

        [$offer, $source, $targetKey] = Tenant::run($shop, function (): array {
            $product = $this->makeProduct(self::PRODUCT_MONTHLY, 'Membership 2675', 49.0);
            $offer = $this->makeOffer();
            $target = $this->addTarget($offer, [
                'product_subscription_plan_id' => $this->makeTemplate($product, BillingFrequency::MONTHLY)->getKey(),
            ]);

            return [$offer, $this->makeSourcePlan(), $target->stableKey()];
        });

        $outcome = Tenant::run($shop, fn (): AccountOfferOutcome => app(AccountOfferAcceptService::class)->accept(
            AccountVisitor::make(
                shop: $shop,
                customerRef: self::MEMBER_REF,
                source: AccountVisitor::SOURCE_WOOCOMMERCE,
                email: self::MEMBER_EMAIL,
            ),
            $source->fresh(),
            (string) $offer->getKey(),
            $targetKey,
            49.0,
        ));

        // The switch itself succeeds — the ledger is the money truth, and a
        // store-side hiccup must never unwind a charge that already happened.
        $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);

        $event = ActivityEvent::query()->withoutGlobalScopes()
            ->where('shop_id', $shop->getKey())
            ->where('kind', Timeline::KIND_STORE_ORDER_FAILED)
            ->first();

        $this->assertNotNull($event, 'the missing store order left no trace on the timeline');
        $this->assertNotNull($event->plan_id);
        $this->assertStringContainsString('400', (string) $event->details['reason']);
    }
}
