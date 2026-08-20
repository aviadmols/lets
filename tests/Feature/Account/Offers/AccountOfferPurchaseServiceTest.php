<?php

namespace Tests\Feature\Account\Offers;

use App\Domain\Account\AccountVisitor;
use App\Domain\Account\Offers\AccountOfferAcceptService;
use App\Domain\Account\Offers\AccountOfferOutcome;
use App\Domain\Billing\IdempotencyKey;
use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use App\Models\CustomerConsent;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A subscriber buys a PLAIN PRODUCT from an offer in their own account area.
 *
 * The two fulfilments are opposites and the tests are written to hold them apart,
 * because the way this feature fails a customer is by confusing them:
 *
 *   BUY NOW      — money moves today. There must be a ledger row on a
 *                  deterministic key, a consent row that PRECEDES it, a paid
 *                  store order carrying the product, and no second charge for a
 *                  second click.
 *
 *   NEXT ORDER   — no money moves at all. There must be NO ledger row, and the
 *                  line must land in the plan's next-order override BESIDE
 *                  whatever was already going out, never instead of it.
 *
 * The fixture is an IMPORTED member throughout (a UUID reference, customer_id
 * null, the card on the plan) because that is the shape in which every shortcut
 * fails.
 */
final class AccountOfferPurchaseServiceTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    // === CONSTANTS ===
    private const MUG_ID = '4242';

    private const MUG_PRICE = 39.0;

    public int $payplusCalls = 0;

    public bool $payplusDeclines = false;

    /** Consent rows for the source plan at the instant PayPlus was entered. */
    public ?int $consentRowsAtChargeTime = null;

    /** @var list<array<string, mixed>> */
    public array $wooOrders = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->payplusCalls = 0;
        $this->payplusDeclines = false;
        $this->consentRowsAtChargeTime = null;
        $this->wooOrders = [];
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private AccountOfferPurchaseServiceTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                $n = ++$this->test->payplusCalls;

                // THE LAW, probed at the instant it matters: a saved-token charge
                // never runs without a stored consent row.
                $this->test->consentRowsAtChargeTime ??= CustomerConsent::withoutGlobalScopes()
                    ->where('consent_context', CustomerConsent::CONTEXT_UPSELL)
                    ->count();

                if ($this->test->payplusDeclines) {
                    return GatewayResult::fromResponse([
                        'results' => ['status' => 'error', 'code' => 51, 'description' => 'Insufficient funds'],
                    ]);
                }

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

        // WooCommerceClient is final (it is transport, and transport is not a seam),
        // so the store is faked at the HTTP boundary instead — which also proves the
        // real payload builder ran rather than a stub of it.
        Http::fake([
            '*/wp-json/wc/v3/orders' => function ($request) use ($test) {
                $test->wooOrders[] = (array) $request->data();

                return Http::response(['id' => 9000 + count($test->wooOrders)], 201);
            },
            '*' => Http::response([], 200),
        ]);
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    // === Buy now ===

    public function test_a_buy_now_purchase_charges_once_writes_the_ledger_and_creates_a_paid_order(): void
    {
        $this->inShop(function (Shop $shop): void {
            [$offer, $target, $source, $visitor] = $this->scenario();

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), $target->stableKey(), self::MUG_PRICE);

            $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);
            $this->assertSame(1, $this->payplusCalls);

            // Buying a mug creates NO subscription. The source plan is what comes
            // back, untouched.
            $this->assertSame(1, InstallmentPlan::count());
            $this->assertSame(PlanStatus::ACTIVE, $source->fresh()->status);

            // The money truth: one succeeded row, on the deterministic key.
            $ledger = PaymentLedger::where('status', LedgerStatus::SUCCEEDED->value)->sole();
            $this->assertSame(
                IdempotencyKey::accountOfferPurchase(
                    (int) $shop->getKey(),
                    (int) $offer->getKey(),
                    (int) $target->getKey(),
                    self::MEMBER_REF,
                    now()->toDateString(),
                ),
                $ledger->idempotency_key,
            );
            $this->assertSame(PaymentLedger::CONTEXT_ACCOUNT_OFFER, $ledger->charge_context);
            $this->assertSame(self::MUG_PRICE, round((float) $ledger->amount, 2));
            // Unlike a post-purchase upsell, we KNOW which subscription it came from.
            $this->assertSame((int) $source->getKey(), (int) $ledger->plan_id);
            $this->assertSame('txn-1', $ledger->payplus_transaction_uid);

            // Consent: an UPSELL-context row on the source plan, already there when
            // the money moved.
            $consent = CustomerConsent::where('consent_context', CustomerConsent::CONTEXT_UPSELL)->sole();
            $this->assertSame((int) $source->getKey(), (int) $consent->plan_id);
            $this->assertSame(self::MEMBER_REF, $consent->shopify_customer_id);
            $this->assertSame(1, $this->consentRowsAtChargeTime, 'Consent must precede the charge.');

            // The store order: paid, completed, carrying the product and linked
            // back to the subscription it was bought from.
            $this->assertCount(1, $this->wooOrders);
            $order = $this->wooOrders[0];
            $this->assertSame('completed', $order['status']);
            $this->assertTrue($order['set_paid']);
            $this->assertSame((int) self::MUG_ID, $order['line_items'][0]['product_id']);
            $this->assertSame('39.00', $order['line_items'][0]['total']);
            $this->assertContains(
                ['key' => 'lets_plan_public_id', 'value' => (string) $source->public_id],
                $order['meta_data'],
            );
            $this->assertSame((string) (9001), (string) $ledger->fresh()->child_order_id);

            $this->assertDatabaseHas('activity_events', [
                'shop_id' => $shop->getKey(),
                'plan_id' => $source->getKey(),
                'kind' => Timeline::KIND_ACCOUNT_OFFER_ACCEPTED,
            ]);
            $this->assertSame(1, (int) $offer->fresh()->accepted_count);
        });
    }

    public function test_a_second_identical_click_does_not_charge_twice(): void
    {
        $this->inShop(function (): void {
            [$offer, $target, $source, $visitor] = $this->scenario();
            $service = $this->service();

            $first = $service->accept($visitor, $source, (string) $offer->getKey(), $target->stableKey(), self::MUG_PRICE);
            $second = $service->accept($visitor, $source->fresh(), (string) $offer->getKey(), $target->stableKey(), self::MUG_PRICE);

            // Both are OK — the shopper asked for one mug and has one mug — but
            // only one charge and one order exist.
            $this->assertSame(AccountOfferOutcome::RESULT_OK, $first->result);
            $this->assertSame(AccountOfferOutcome::RESULT_OK, $second->result);

            $this->assertSame(1, $this->payplusCalls, 'The succeeded ledger row short-circuits the second click.');
            $this->assertSame(1, PaymentLedger::count());
            $this->assertCount(1, $this->wooOrders);
        });
    }

    public function test_a_declined_card_writes_a_failed_row_and_creates_no_order(): void
    {
        $this->inShop(function (Shop $shop): void {
            [$offer, $target, $source, $visitor] = $this->scenario();
            $this->payplusDeclines = true;

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), $target->stableKey(), self::MUG_PRICE);

            $this->assertSame(AccountOfferOutcome::RESULT_CHARGE_FAILED, $outcome->result);

            $ledger = PaymentLedger::sole();
            $this->assertSame(LedgerStatus::FAILED->value, $ledger->status);
            $this->assertSame('51', (string) $ledger->failure_code);

            $this->assertSame([], $this->wooOrders, 'No money, no order.');
            $this->assertSame(0, (int) $offer->fresh()->accepted_count);
            $this->assertDatabaseHas('activity_events', [
                'plan_id' => $source->getKey(),
                'kind' => Timeline::KIND_ACCOUNT_OFFER_CHARGE_FAILED,
            ]);
        });
    }

    public function test_a_price_that_moved_under_the_click_refuses_rather_than_charging_either_number(): void
    {
        $this->inShop(function (): void {
            [$offer, $target, $source, $visitor] = $this->scenario();

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), $target->stableKey(), 9.0);

            $this->assertSame(AccountOfferOutcome::RESULT_CHANGED, $outcome->result);
            $this->assertSame(0, $this->payplusCalls);
            $this->assertSame(0, PaymentLedger::count());
        });
    }

    // === Next order ===

    public function test_a_next_order_add_on_takes_no_money_and_lands_in_the_next_order_override(): void
    {
        $this->inShop(function (): void {
            [$offer, $target, $source, $visitor] = $this->scenario(
                ['fulfilment' => AccountOfferTarget::FULFILMENT_NEXT_ORDER, 'quantity' => 2],
            );

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), $target->stableKey(), 78.0);

            $this->assertSame(AccountOfferOutcome::RESULT_OK, $outcome->result);
            $this->assertSame(0, $this->payplusCalls, 'Nothing is charged today.');
            $this->assertSame(0, PaymentLedger::count(), 'No charge, no ledger row.');
            $this->assertSame([], $this->wooOrders);

            $override = $source->fresh()->nextOrderOverride();
            $this->assertNotNull($override);

            // TWO lines: the subscription the shopper is already paying for, and
            // the add-on beside it. An override REPLACES the cycle's contents, so
            // seeding the plan's own line is what keeps the box from arriving with
            // nothing but a mug in it.
            $this->assertCount(2, $override['line_items']);
            $this->assertSame(480.0, (float) $override['line_items'][0]['unit_price'], "the plan's own cycle");
            $this->assertSame((int) self::MUG_ID, $override['line_items'][1]['product_id']);
            $this->assertSame(2, $override['line_items'][1]['quantity']);
            $this->assertSame(self::MUG_PRICE, (float) $override['line_items'][1]['unit_price']);
            $this->assertSame(558.0, (float) $override['amount']);

            $this->assertSame(1, (int) $offer->fresh()->accepted_count);
        });
    }

    /** A merchant's edit and a shopper's add-on must BOTH survive, in either order. */
    public function test_an_add_on_merges_into_an_existing_override_rather_than_replacing_it(): void
    {
        $this->inShop(function (): void {
            [$offer, $target, $source, $visitor] = $this->scenario(
                ['fulfilment' => AccountOfferTarget::FULFILMENT_NEXT_ORDER],
            );

            // The merchant has already edited next month's box.
            $source->forceFill(['meta' => array_merge((array) $source->meta, [
                InstallmentPlan::META_NEXT_ORDER => [
                    'line_items' => [[
                        'product_id' => 7777,
                        'name' => 'Merchant special',
                        'quantity' => 1,
                        'unit_price' => 100.0,
                    ]],
                    'amount' => 100.0,
                    'currency' => 'ILS',
                    'set_by' => 'admin:1',
                    'set_at' => now()->subDay()->toIso8601String(),
                ],
            ])])->save();

            $this->service()->accept($visitor, $source->fresh(), (string) $offer->getKey(), $target->stableKey(), self::MUG_PRICE);

            $override = $source->fresh()->nextOrderOverride();

            $this->assertCount(2, $override['line_items'], "the merchant's line survives");
            $this->assertSame(7777, $override['line_items'][0]['product_id']);
            $this->assertSame((int) self::MUG_ID, $override['line_items'][1]['product_id']);
            $this->assertSame(139.0, (float) $override['amount'], 'the amount is re-summed, never guessed');
            // The last writer is named, and it is not the admin who wrote it first.
            $this->assertSame('customer', $override['set_by']);
            $this->assertNotSame('admin:1', $override['set_by']);
        });
    }

    public function test_an_add_on_is_refused_when_the_subscription_has_no_next_charge(): void
    {
        $this->inShop(function (): void {
            [$offer, $target, $source, $visitor] = $this->scenario(
                ['fulfilment' => AccountOfferTarget::FULFILMENT_NEXT_ORDER],
                ['next_charge_at' => null],
            );

            $outcome = $this->service()->accept($visitor, $source, (string) $offer->getKey(), $target->stableKey(), self::MUG_PRICE);

            // Nothing to ride on. Refused honestly rather than held for a date the
            // shopper was never told about.
            $this->assertContains(
                $outcome->result,
                [AccountOfferOutcome::RESULT_UNAVAILABLE, AccountOfferOutcome::RESULT_NOT_ELIGIBLE],
            );
            $this->assertSame(0, $this->payplusCalls);
            $this->assertNull($source->fresh()->nextOrderOverride());
        });
    }

    // === Walls ===

    public function test_a_shop_with_live_charging_off_refuses_a_buy_now_but_allows_a_next_order(): void
    {
        $this->inShop(function (): void {
            [$offer, $buyNow, $source, $visitor] = $this->scenario();
            $rideAlong = $this->addTarget($offer, [
                'kind' => AccountOfferTarget::KIND_ONE_TIME,
                'external_product_id' => self::MUG_ID,
                'token_key' => 'later',
                'fulfilment' => AccountOfferTarget::FULFILMENT_NEXT_ORDER,
            ]);

            MerchantBillingSettings::current()->forceFill(['live_charging_enabled' => false])->save();

            $refused = $this->service()->accept($visitor, $source, (string) $offer->getKey(), $buyNow->stableKey(), self::MUG_PRICE);
            $this->assertSame(AccountOfferOutcome::RESULT_UNAVAILABLE, $refused->result);
            $this->assertSame(0, $this->payplusCalls, 'The wall is never bypassed.');

            $allowed = $this->service()->accept($visitor, $source->fresh(), (string) $offer->getKey(), $rideAlong->stableKey(), self::MUG_PRICE);
            $this->assertSame(AccountOfferOutcome::RESULT_OK, $allowed->result, 'No money moves, so nothing is held back.');
        });
    }

    public function test_a_target_key_from_another_shops_offer_never_resolves(): void
    {
        $shopA = $this->makeShop('purchase-a.example.com');
        $shopB = $this->makeShop('purchase-b.example.com');

        $foreignOfferId = Tenant::run($shopB, function (): string {
            $this->makeProduct(self::MUG_ID, 'Their mug', self::MUG_PRICE);
            $offer = $this->makeOffer();
            $this->addTarget($offer, [
                'kind' => AccountOfferTarget::KIND_ONE_TIME,
                'external_product_id' => self::MUG_ID,
                'token_key' => 'mug',
            ]);

            return (string) $offer->getKey();
        });

        Tenant::run($shopA, function () use ($shopA, $foreignOfferId): void {
            $this->makeProduct(self::MUG_ID, 'Our mug', self::MUG_PRICE);
            $source = $this->makeSourcePlan();

            $outcome = $this->service()->accept($this->visitor($shopA), $source, $foreignOfferId, 'mug', self::MUG_PRICE);

            // The row does not exist for this tenant. Same answer as a typo.
            $this->assertSame(AccountOfferOutcome::RESULT_INVALID, $outcome->result);
            $this->assertSame(0, $this->payplusCalls);
            $this->assertSame(0, PaymentLedger::withoutGlobalScopes()->count());
        });
    }

    // === Helpers ===

    private function inShop(callable $callback): void
    {
        $shop = $this->makeShop();
        $shop->forceFill([
            'woocommerce_domain' => 'wc.example.com',
            'woocommerce_credentials' => [
                'base_url' => 'https://wc.example.com',
                'consumer_key' => 'ck_x',
                'consumer_secret' => 'cs_x',
            ],
        ])->save();

        Tenant::run($shop->refresh(), fn () => $callback($shop));
    }

    private function service(): AccountOfferAcceptService
    {
        return app(AccountOfferAcceptService::class);
    }

    private function visitor(Shop $shop): AccountVisitor
    {
        return AccountVisitor::make(
            shop: $shop,
            customerRef: self::MEMBER_REF,
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
            email: self::MEMBER_EMAIL,
        );
    }

    /**
     * A yearly member, offered a mug from inside their own account area.
     *
     * @param  array<string, mixed>  $targetAttributes
     * @param  array<string, mixed>  $planAttributes
     * @return array{0: AccountOffer, 1: AccountOfferTarget, 2: InstallmentPlan, 3: AccountVisitor}
     */
    private function scenario(array $targetAttributes = [], array $planAttributes = []): array
    {
        $shop = Tenant::current();
        $this->makeProduct(self::MUG_ID, 'Club mug', self::MUG_PRICE);

        $offer = $this->makeOffer(null, ['name' => 'Add the mug']);
        $target = $this->addTarget($offer, array_merge([
            'kind' => AccountOfferTarget::KIND_ONE_TIME,
            'external_product_id' => self::MUG_ID,
            'token_key' => 'mug',
        ], $targetAttributes));

        return [$offer->fresh(), $target, $this->makeSourcePlan($planAttributes), $this->visitor($shop)];
    }
}
