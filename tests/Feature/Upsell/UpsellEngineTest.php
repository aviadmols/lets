<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\AcceptUpsellRequest;
use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Domain\Upsell\PurchaseContext;
use App\Domain\Upsell\UpsellChargeResult;
use App\Domain\Upsell\UpsellChargeService;
use App\Domain\Upsell\UpsellMetrics;
use App\Domain\Upsell\UpsellResolver;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\Shopify\Orders\ShopifyDraftOrderService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * Phase 6 — post-purchase / thank-you-page upsell on the saved PayPlus token.
 * Proves the money law survives the upsell context:
 *   - the resolver picks the first ACTIVE flow by priority + records an impression;
 *   - a one-click accept charges EXACTLY once (double-click → ONE charge) and
 *     records charge_succeeded + revenue + creates the linked child order;
 *   - no consent → fail closed (no charge);
 *   - decline records `declined` and never charges;
 *   - the metrics math is correct.
 */
final class UpsellEngineTest extends TestCase
{
    use RefreshDatabase;

    public int $payplusCalls = 0;

    public RecordingShopifyClient $shopifyClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payplusCalls = 0;
        $test = $this;

        // Fake PayPlus: every charge succeeds with a unique txn uid; count calls.
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface {
            public function __construct(private UpsellEngineTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $n = ++$this->test->payplusCalls;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$n, 'approval_number' => 'A'.$n]],
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

        $this->shopifyClient = new RecordingShopifyClient();
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    // === Trigger evaluation ===

    public function test_resolver_picks_lowest_priority_matching_flow_and_records_impression(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            // A high-priority (number 10) flow that matches, and a lower-priority
            // (number 5, evaluated first) flow that ALSO matches — the 5 wins.
            $winner = $this->makeFlow($shop, 'First', priority: 5, productGid: 'gid://shopify/Product/1');
            $loser = $this->makeFlow($shop, 'Second', priority: 10, productGid: 'gid://shopify/Product/1');
            // An inactive flow with priority 1 (would win) must be ignored.
            $inactive = $this->makeFlow($shop, 'Inactive', priority: 1, productGid: 'gid://shopify/Product/1', status: UpsellFlowStatus::DRAFT);

            $context = new PurchaseContext(
                shopId: (int) $shop->getKey(),
                parentOrderId: '5001',
                customerRef: 'cust-1',
                orderSubtotal: 120.0,
                purchasedProductGids: ['gid://shopify/Product/1'],
            );

            $resolution = app(UpsellResolver::class)->resolve($context);

            $this->assertNotNull($resolution);
            $this->assertSame($winner->id, $resolution->flow->id, 'Lowest priority active matching flow wins.');

            // Exactly one impression, for the winning flow's first offer.
            $this->assertSame(1, UpsellOfferEvent::where('event_type', OfferEventType::IMPRESSION->value)->count());
            $this->assertDatabaseHas('upsell_offer_events', [
                'shop_id' => $shop->id,
                'flow_id' => $winner->id,
                'event_type' => OfferEventType::IMPRESSION->value,
            ]);
        });
    }

    public function test_resolver_returns_null_when_no_trigger_matches(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $this->makeFlow($shop, 'Flow', priority: 5, productGid: 'gid://shopify/Product/999');

            $context = new PurchaseContext(
                shopId: (int) $shop->getKey(),
                parentOrderId: '5001',
                customerRef: 'cust-1',
                orderSubtotal: 10.0,
                purchasedProductGids: ['gid://shopify/Product/1'],
            );

            $this->assertNull(app(UpsellResolver::class)->resolve($context));
            $this->assertSame(0, UpsellOfferEvent::count());
        });
    }

    // === One-click accept ===

    public function test_double_clicked_accept_charges_exactly_once_and_creates_child_order(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            [$flow, $offer, $method] = $this->makeFlowWithConsent($shop, customerRef: 'cust-42', base: 100.0);

            $req = new AcceptUpsellRequest(
                flow: $flow,
                offer: $offer,
                parentOrderId: 'P-900',
                customerRef: 'cust-42',
                customerEmail: 'buyer@example.com',
            );

            $service = $this->chargeService();

            // First click — charges, creates child order.
            $first = $service->accept($shop, $req);
            $this->assertSame(UpsellChargeResult::RESULT_CHARGED, $first->result);

            // Second click (double-click / retry) — idempotent short-circuit.
            $second = $service->accept($shop, $req);
            $this->assertSame(UpsellChargeResult::RESULT_ALREADY, $second->result);

            // EXACTLY ONE PayPlus charge for both clicks.
            $this->assertSame(1, $this->payplusCalls, 'Double-click must collapse to one charge.');

            // One succeeded upsell ledger row (plan-less).
            $ledgers = PaymentLedger::where('shop_id', $shop->id)
                ->where('charge_context', PaymentLedger::CONTEXT_UPSELL)
                ->where('status', LedgerStatus::SUCCEEDED->value)
                ->get();
            $this->assertCount(1, $ledgers);
            $this->assertNull($ledgers->first()->plan_id, 'Upsell is a context, not a plan.');

            // charge_succeeded recorded with revenue = discounted price.
            $this->assertDatabaseHas('upsell_offer_events', [
                'shop_id' => $shop->id,
                'offer_id' => $offer->id,
                'event_type' => OfferEventType::CHARGE_SUCCEEDED->value,
                'revenue_amount' => 90.00, // 100 - 10% = 90
            ]);

            // The linked child order was created via the Phase-4 seam (one draft
            // completed-as-paid), and the ledger captured its id.
            $this->assertSame('888', $ledgers->first()->child_order_id);
        });
    }

    public function test_accept_without_consent_fails_closed_with_no_charge(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            // Flow + saved token, but NO consent row.
            [$flow, $offer] = $this->makeFlowAndOffer($shop, base: 50.0);
            $this->makeActiveToken($shop, 'cust-77');

            $req = new AcceptUpsellRequest($flow, $offer, 'P-1', 'cust-77', 'x@y.com');
            $result = $this->chargeService()->accept($shop, $req);

            $this->assertSame(UpsellChargeResult::RESULT_NO_CONSENT, $result->result);
            $this->assertSame(0, $this->payplusCalls, 'No PayPlus call without consent.');
            $this->assertSame(0, PaymentLedger::where('status', LedgerStatus::SUCCEEDED->value)->count());
        });
    }

    public function test_decline_records_declined_and_never_charges(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            [$flow, $offer] = $this->makeFlowAndOffer($shop, base: 50.0);

            $req = new AcceptUpsellRequest($flow, $offer, 'P-2', 'cust-9');
            $result = $this->chargeService()->decline((int) $shop->getKey(), $req);

            $this->assertFalse($result->isCharged() && $result->result === UpsellChargeResult::RESULT_CHARGED);
            $this->assertSame(0, $this->payplusCalls);
            $this->assertDatabaseHas('upsell_offer_events', [
                'shop_id' => $shop->id,
                'offer_id' => $offer->id,
                'event_type' => OfferEventType::DECLINED->value,
            ]);
            $this->assertSame(0, PaymentLedger::count(), 'Decline writes no ledger row.');
        });
    }

    // === Metrics ===

    public function test_metrics_compute_conversion_revenue_and_aov(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $flow = $this->makeFlow($shop, 'F', priority: 5, productGid: 'gid://shopify/Product/1');
            $offer = $flow->offers()->first();

            // 4 impressions, 2 accepted, 1 declined, 2 charge_succeeded (revenue 90+60).
            $base = ['shop_id' => $shop->id, 'flow_id' => $flow->id, 'offer_id' => $offer->id, 'currency' => 'ILS'];
            foreach (range(1, 4) as $i) {
                UpsellOfferEvent::record($base + ['event_type' => OfferEventType::IMPRESSION]);
            }
            UpsellOfferEvent::record($base + ['event_type' => OfferEventType::ACCEPTED]);
            UpsellOfferEvent::record($base + ['event_type' => OfferEventType::ACCEPTED]);
            UpsellOfferEvent::record($base + ['event_type' => OfferEventType::DECLINED]);
            UpsellOfferEvent::record($base + ['event_type' => OfferEventType::CHARGE_SUCCEEDED, 'revenue_amount' => 90.0]);
            UpsellOfferEvent::record($base + ['event_type' => OfferEventType::CHARGE_SUCCEEDED, 'revenue_amount' => 60.0]);

            $m = app(UpsellMetrics::class)->overview();

            $this->assertSame(4, $m['impressions']);
            $this->assertSame(2, $m['accepted']);
            $this->assertSame(1, $m['declined']);
            $this->assertSame(2, $m['charge_succeeded']);
            $this->assertSame(0.5, $m['conversion_rate']);       // 2/4
            $this->assertSame(1.0, $m['charge_success_rate']);   // 2/2
            $this->assertSame(150.0, $m['total_revenue']);       // 90 + 60
            $this->assertSame(75.0, $m['aov_uplift']);           // 150 / 2
        });
    }

    // === Helpers ===

    private function chargeService(): UpsellChargeService
    {
        return new UpsellChargeService(
            resolver: app(UpsellResolver::class),
            draftOrderFactory: fn (Shop $shop): ShopifyDraftOrderService => new ShopifyDraftOrderService($this->shopifyClient),
        );
    }

    private function makeShop(string $domain = 'upsell.myshopify.com'): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => 'Upsell Co',
            'status' => Shop::STATUS_INSTALLED,
            'shopify_access_token' => 'shpat_token',
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return $shop;
    }

    private function makeFlow(Shop $shop, string $name, int $priority, string $productGid, UpsellFlowStatus $status = UpsellFlowStatus::ACTIVE): UpsellFlow
    {
        $flow = new UpsellFlow(['name' => $name, 'priority' => $priority]);
        $flow->shop_id = $shop->id;
        $flow->forceFill(['status' => $status->value])->save();

        UpsellFlowTrigger::create([
            'flow_id' => $flow->id,
            'match_type' => UpsellFlowTrigger::MATCH_SPECIFIC_PRODUCT,
            'shopify_product_gid' => $productGid,
        ]);

        UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/77',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/770',
            'offer_title' => 'Add-on',
            'base_price' => 50.0,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'position' => 0,
        ]);

        return $flow->fresh();
    }

    /** @return array{0: UpsellFlow, 1: UpsellFlowOffer} */
    private function makeFlowAndOffer(Shop $shop, float $base): array
    {
        $flow = new UpsellFlow(['name' => 'Flow', 'priority' => 5]);
        $flow->shop_id = $shop->id;
        $flow->forceFill(['status' => UpsellFlowStatus::ACTIVE->value])->save();

        $offer = UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/77',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/770',
            'offer_title' => 'Add-on',
            'base_price' => $base,
            'discount_type' => UpsellFlowOffer::DISCOUNT_PERCENT,
            'discount_value' => 10,
            'position' => 0,
        ]);

        return [$flow->fresh(), $offer];
    }

    /** @return array{0: UpsellFlow, 1: UpsellFlowOffer, 2: InstallmentPaymentMethod} */
    private function makeFlowWithConsent(Shop $shop, string $customerRef, float $base): array
    {
        [$flow, $offer] = $this->makeFlowAndOffer($shop, $base);
        $method = $this->makeActiveToken($shop, $customerRef);

        CustomerConsent::create([
            'shopify_customer_id' => $customerRef,
            'consent_context' => CustomerConsent::CONTEXT_UPSELL,
            'accepted_at' => now(),
        ]);

        return [$flow, $offer, $method];
    }

    private function makeActiveToken(Shop $shop, string $customerRef): InstallmentPaymentMethod
    {
        return InstallmentPaymentMethod::create([
            'shopify_customer_id' => $customerRef,
            'payplus_card_token_uid' => 'tok-1',
            'payplus_customer_uid' => 'cust-uid-1',
            'card_last_four' => '4242',
            'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
        ]);
    }
}
