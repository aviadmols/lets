<?php

namespace Tests\Feature\Account\Offers;

use App\Domain\Account\AccountVisitor;
use App\Domain\Account\Offers\AccountOfferAcceptService;
use App\Domain\Account\Offers\AccountOfferOutcome;
use App\Domain\Account\Offers\AccountOfferQuote;
use App\Domain\Account\Offers\OfferOrderWriter;
use App\Domain\Account\Offers\OfferOrderWriterFactory;
use App\Domain\Account\Offers\ShopifyAccountOfferOrderWriter;
use App\Listeners\Loyalty\AccruePointsFromShopifyOrder;
use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\LoyaltyPointEvent;
use App\Models\MerchantLoyaltySettings;
use App\Models\PaymentLedger;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * The Shopify half of the account-offer order seam — closing the hole where a
 * Shopify shopper's buy-now click charged the saved PayPlus token and recorded
 * NO store order at all.
 *
 * Three laws under test:
 *   1. the sale becomes a draft-completed-as-paid order carrying the role
 *      attributes and the SERVER-pinned price;
 *   2. a shop that cannot record the order refuses BEFORE any money moves;
 *   3. the recorded order never earns loyalty points a second time — the
 *      pps_plan_public_id note attribute is the tell the accrual listener reads.
 */
final class ShopifyAccountOfferPurchaseTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    // === CONSTANTS ===
    private const MUG_ID = '4242';

    private const MUG_PRICE = 39.0;

    public int $payplusCalls = 0;

    private RecordingShopifyClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->payplusCalls = 0;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private ShopifyAccountOfferPurchaseTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $n = ++$this->test->payplusCalls;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['transaction' => ['uid' => 'txn-'.$n]],
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

        $this->client = new RecordingShopifyClient;
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $this->client);
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        ShopifyClientFactory::clearFake();
        OfferOrderWriterFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_purchase_resolves_its_writer_through_the_factory_seam(): void
    {
        $this->inShopifyShop(function (Shop $shop): void {
            [$offer, $target, $source, $visitor] = $this->shopifyScenario();

            $fake = new class implements OfferOrderWriter
            {
                public int $created = 0;

                public function available(Shop $shop): bool
                {
                    return true;
                }

                public function create(Shop $shop, InstallmentPlan $source, AccountOffer $offer, AccountOfferTarget $target, AccountOfferQuote $quote): ?string
                {
                    $this->created++;

                    return '424242';
                }
            };
            OfferOrderWriterFactory::fake($fake);

            $outcome = app(AccountOfferAcceptService::class)
                ->accept($visitor, $source, (string) $offer->getKey(), $target->stableKey(), self::MUG_PRICE);

            $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);
            $this->assertSame(1, $fake->created);
            $this->assertSame([], $this->client->draftOrders, 'The faked seam must bypass the real client.');
            $this->assertSame('424242', (string) PaymentLedger::sole()->child_order_id);
        });
    }

    public function test_a_buy_now_purchase_records_a_draft_completed_order_with_the_role_attributes(): void
    {
        $this->inShopifyShop(function (Shop $shop): void {
            [$offer, $target, $source, $visitor] = $this->shopifyScenario();

            $outcome = app(AccountOfferAcceptService::class)
                ->accept($visitor, $source, (string) $offer->getKey(), $target->stableKey(), self::MUG_PRICE);

            $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);
            $this->assertSame(1, $this->payplusCalls);

            // The order: one draft, completed as paid, with the whole paper trail.
            $this->assertCount(1, $this->client->draftOrders);
            $draft = $this->client->draftOrders[0];

            $attributes = collect($draft['note_attributes'])->pluck('value', 'name');
            $this->assertSame(ShopifyAccountOfferOrderWriter::ROLE_ACCOUNT_OFFER, $attributes['pps_order_role']);
            $this->assertSame((string) $source->public_id, $attributes['pps_plan_public_id']);
            $this->assertSame((string) $offer->getKey(), $attributes[ShopifyAccountOfferOrderWriter::META_OFFER_ID]);
            $this->assertSame((string) $target->getKey(), $attributes[ShopifyAccountOfferOrderWriter::META_TARGET_ID]);

            // Money law: the line price is the SERVER's, pinned to what was charged.
            // The variant id is the NUMBER — this is the REST draft channel, and a
            // GID here would bounce the whole draft (charged sale, no order).
            $line = $draft['line_items'][0];
            $this->assertSame('39.00', $line['price']);
            $this->assertSame((int) self::MUG_ID, $line['variant_id']);

            // An imported member's UUID ref is not a REST customer id — the
            // draft must not carry a customer block it would bounce on.
            $this->assertArrayNotHasKey('customer', $draft);

            // The ledger row carries the recorded order id (completeDraftOrder → 888).
            $ledger = PaymentLedger::where('status', LedgerStatus::SUCCEEDED->value)->sole();
            $this->assertSame('888', (string) $ledger->child_order_id);
        });
    }

    public function test_a_disconnected_shop_refuses_before_any_money_moves(): void
    {
        $this->inShopifyShop(function (Shop $shop): void {
            [$offer, $target, $source, $visitor] = $this->shopifyScenario();
            $shop->forceFill(['shopify_access_token' => null])->save();
            $source->unsetRelation('shop');

            $outcome = app(AccountOfferAcceptService::class)
                ->accept($visitor, $source, (string) $offer->getKey(), $target->stableKey(), self::MUG_PRICE);

            $this->assertSame(AccountOfferOutcome::RESULT_UNAVAILABLE, $outcome->result);
            $this->assertSame(0, $this->payplusCalls, 'No charge may fire when the store cannot record the order.');
            $this->assertSame(0, PaymentLedger::count(), 'Fail closed means no ledger row at all.');
        });
    }

    public function test_a_store_that_refuses_the_draft_leaves_the_charge_standing_and_flags_reconcile(): void
    {
        $this->inShopifyShop(function (Shop $shop): void {
            [$offer, $target, $source, $visitor] = $this->shopifyScenario();
            $this->client->draftOrderThrows = new \RuntimeException('store said no');

            $outcome = app(AccountOfferAcceptService::class)
                ->accept($visitor, $source, (string) $offer->getKey(), $target->stableKey(), self::MUG_PRICE);

            // The shopper WAS charged; the outcome stays ok and the gap is flagged.
            $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);

            $ledger = PaymentLedger::where('status', LedgerStatus::SUCCEEDED->value)->sole();
            $this->assertNull($ledger->child_order_id);

            $reconcile = ActivityEvent::query()->withoutGlobalScopes()
                ->where('shop_id', $shop->getKey())
                ->where('kind', Timeline::KIND_ACCOUNT_OFFER_CHARGE_FAILED)
                ->get()
                ->first(fn (ActivityEvent $event): bool => ($event->details['reason'] ?? null) === 'order_not_created');
            $this->assertNotNull($reconcile, 'The merchant must have a reconcile trace to find.');
        });
    }

    public function test_the_recorded_order_never_earns_points_twice(): void
    {
        $this->inShopifyShop(function (Shop $shop): void {
            MerchantLoyaltySettings::current()->forceFill([
                'enabled' => true, 'points_per_currency' => 1, 'join_bonus_points' => 0,
            ])->save();

            [$offer, $target, $source, $visitor] = $this->shopifyScenario();

            app(AccountOfferAcceptService::class)
                ->accept($visitor, $source, (string) $offer->getKey(), $target->stableKey(), self::MUG_PRICE);

            // The webhook for the order WE just created arrives, carrying the tell.
            Tenant::clear();
            (new AccruePointsFromShopifyOrder)->handle([
                'shop_id' => $shop->getKey(),
                'order_id' => '888',
                'payload' => [
                    'customer' => ['id' => 42],
                    'total_price' => '39.00',
                    'note_attributes' => [
                        ['name' => 'pps_plan_public_id', 'value' => (string) $source->public_id],
                    ],
                ],
            ]);

            Tenant::run($shop, function (): void {
                $this->assertSame(
                    0,
                    LoyaltyPointEvent::query()
                        ->where('idempotency_key', LoyaltyPointEvent::keyForShopifyOrder('888'))
                        ->count(),
                    'An order the app created must never mint points through the webhook.',
                );
            });
        });
    }

    // === Fixtures ===

    private function inShopifyShop(callable $callback): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'offers-shopify.myshopify.com',
            'name' => 'Shopify Offers Co',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->forceFill(['shopify_access_token' => 'shpat_test'])->save();

        Tenant::run($shop->refresh(), fn () => $callback($shop->refresh()));
    }

    /** @return array{0: AccountOffer, 1: AccountOfferTarget, 2: InstallmentPlan, 3: AccountVisitor} */
    private function shopifyScenario(): array
    {
        $shop = Tenant::current();

        // The catalog product on the SHOPIFY source — AccountOfferQuote resolves
        // products by the shop's own platform.
        $product = Product::create([
            'source' => Product::SOURCE_SHOPIFY,
            'external_id' => self::MUG_ID,
            'title' => 'Club mug',
            'status' => Product::STATUS_ACTIVE,
        ]);
        ProductVariant::create([
            'product_id' => $product->getKey(),
            'external_variant_id' => self::MUG_ID,
            'title' => '',
            'price' => self::MUG_PRICE,
            'position' => 0,
        ]);

        $offer = $this->makeOffer(null, ['name' => 'Add the mug']);
        $target = $this->addTarget($offer, [
            'kind' => AccountOfferTarget::KIND_ONE_TIME,
            'external_product_id' => self::MUG_ID,
            'token_key' => 'mug',
        ]);

        $source = $this->makeSourcePlan();

        $visitor = AccountVisitor::make(
            shop: $shop,
            customerRef: self::MEMBER_REF,
            source: AccountVisitor::SOURCE_EXTENSION,
            email: self::MEMBER_EMAIL,
        );

        return [$offer->fresh(), $target, $source, $visitor];
    }
}
