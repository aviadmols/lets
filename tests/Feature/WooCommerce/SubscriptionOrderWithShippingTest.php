<?php

namespace Tests\Feature\WooCommerce;

use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentIssuer;
use App\Domain\Invoicing\DocumentLine;
use App\Domain\Invoicing\DocumentReconciliationService;
use App\Domain\Invoicing\InvoiceProviderFactory;
use App\Domain\Invoicing\IssuedDocumentResult;
use App\Domain\Invoicing\IssueDocumentRequest;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Domain\Invoicing\OrderDocumentHold;
use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Models\InstallmentPlan;
use App\Models\IssuedDocument;
use App\Models\MerchantInvoicingSettings;
use App\Models\PaymentLedger;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesPayPlusIpn;
use Tests\TestCase;

/**
 * A checkout of a subscription (100), a one-time book (80) and shipping (30), paid as one 210
 * PayPlus transaction. The document declares all 210, the ledger records all 210, and any part
 * of it can go back through PayPlus.
 */
final class SubscriptionOrderWithShippingTest extends TestCase
{
    use FakesPayPlusIpn;
    use RefreshDatabase;

    private const ORDER = '9100';

    private const TRANSACTION = 'txn-9100';

    /** @var list<array{uid: string, amount: float}> */
    public array $refunds = [];

    /** @var list<IssueDocumentRequest> */
    public array $issued = [];

    public bool $providerRefuses = false;

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        InvoiceProviderFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_one_document_declares_everything_the_checkout_paid_for(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        $this->paidCheckout($shop, $key, $secret);

        $this->assertCount(1, $this->issued, 'One payment, one document.');
        $document = $this->issued[0];

        $this->assertEqualsWithDelta(210.0, $document->amount, 0.001);
        $this->assertSame(
            ['Subscription:100', 'Book:80', 'משלוח:30'],
            array_map(static fn (DocumentLine $l): string => $l->description.':'.(int) $l->total(), $document->lines),
        );
        $this->assertSame('4242', $document->cardLast4);

        Tenant::run($shop, function (): void {
            $row = IssuedDocument::query()->sole();
            $this->assertSame(self::ORDER, $row->external_order_id);
            $this->assertNotNull($row->plan_id, 'The document still shows on the plan.');
            $this->assertNull($row->ledger_id, 'A retry rebuilds it from the order, never from one charge.');
        });
    }

    public function test_a_failed_document_retries_as_the_whole_order(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        $this->providerRefuses = true;
        $this->paidCheckout($shop, $key, $secret);
        $this->providerRefuses = false;

        Tenant::run($shop, function (): void {
            $document = IssuedDocument::query()->sole();
            $this->assertSame(IssuedDocument::STATUS_FAILED, $document->status);
            $this->assertTrue(app(DocumentReconciliationService::class)->retry($document)['ok']);
        });

        $last = $this->issued[array_key_last($this->issued)];
        $this->assertEqualsWithDelta(210.0, $last->amount, 0.001);
        $this->assertCount(3, $last->lines);
        Tenant::run($shop, fn () => $this->assertSame(IssuedDocument::STATUS_ISSUED, IssuedDocument::query()->sole()->status));
    }

    /** Reloading the thank-you page of an order whose plan was already documented on its own. */
    public function test_a_confirmation_for_an_already_documented_older_order_issues_nothing_new(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        $this->paidCheckout($shop, $key, $secret);

        Tenant::run($shop, function () use ($shop): void {
            IssuedDocument::query()->delete();
            PaymentLedger::query()->where('charge_context', PaymentLedger::CONTEXT_GATEWAY)->delete();

            $firstCycle = PaymentLedger::query()->sole();
            IssuedDocument::query()->forceCreate([
                'shop_id' => (int) $shop->getKey(),
                'plan_id' => $firstCycle->plan_id,
                'ledger_id' => $firstCycle->getKey(),
                'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
                'context' => 'recurring',
                'idempotency_key' => DocumentIssuer::keyForLedger($firstCycle),
                'amount' => 100,
                'currency' => 'ILS',
                'status' => IssuedDocument::STATUS_ISSUED,
            ]);
        });
        $this->issued = [];

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:'.self::ORDER, 'status_code' => '000',
        ]))->assertOk();

        $this->assertSame([], $this->issued);
        Tenant::run($shop, fn () => $this->assertSame(0, PaymentLedger::query()->where('charge_context', PaymentLedger::CONTEXT_GATEWAY)->count()));

        // Still true once that first cycle has gone back: its document declared the money.
        Tenant::run($shop, fn () => PaymentLedger::query()->sole()->forceFill(['status' => 'refunded'])->save());

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:'.self::ORDER, 'status_code' => '000',
        ]))->assertOk();

        $this->assertSame([], $this->issued);
    }

    public function test_a_shop_documenting_plans_only_still_gets_the_whole_order_on_its_document(): void
    {
        [$shop, $key, $secret] = $this->connectedShop(scope: MerchantInvoicingSettings::SCOPE_PLANS_ONLY);
        $this->paidCheckout($shop, $key, $secret);

        $this->assertCount(1, $this->issued);
        $this->assertEqualsWithDelta(210.0, $this->issued[0]->amount, 0.001);
    }

    public function test_a_replayed_confirmation_issues_no_second_document_and_records_no_second_row(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        $this->paidCheckout($shop, $key, $secret);

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:'.self::ORDER, 'status_code' => '000',
        ]))->assertOk();

        $this->assertCount(1, $this->issued);
        Tenant::run($shop, fn () => $this->assertSame(2, PaymentLedger::query()->count()));
    }

    public function test_the_ledger_adds_up_to_the_transaction_and_both_rows_name_it(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        $this->paidCheckout($shop, $key, $secret);

        Tenant::run($shop, function (): void {
            $rows = PaymentLedger::query()->orderBy('id')->get();

            $this->assertSame([PaymentLedger::CONTEXT_RECURRING, PaymentLedger::CONTEXT_GATEWAY], $rows->pluck('charge_context')->all());
            $this->assertEqualsWithDelta(100.0, (float) $rows[0]->amount, 0.001);
            $this->assertEqualsWithDelta(110.0, (float) $rows[1]->amount, 0.001);
            $this->assertSame([self::TRANSACTION, self::TRANSACTION], $rows->pluck('payplus_transaction_uid')->all());
        });
    }

    public function test_a_subscription_alone_records_no_second_row(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        $this->paidCheckout($shop, $key, $secret, total: 100.0, book: 0.0, shipping: 0.0);

        Tenant::run($shop, fn () => $this->assertSame(1, PaymentLedger::query()->count()));
        $this->assertEqualsWithDelta(100.0, $this->issued[0]->amount, 0.001);
    }

    public function test_the_shipping_can_be_refunded_on_its_own_and_the_subscription_stays_whole(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        $this->paidCheckout($shop, $key, $secret);

        $state = $this->signedPost($key, $secret, '/api/woocommerce/orders/'.self::ORDER.'/refund-state', []);
        $state->assertJsonPath('state.rail', 'payplus');
        $this->assertEqualsWithDelta(210.0, (float) $state->json('state.refundable'), 0.001);

        $this->signedPost($key, $secret, '/api/woocommerce/orders/'.self::ORDER.'/refund', ['amount' => 30.0, 'reason' => 'shipping'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame([['uid' => self::TRANSACTION, 'amount' => 30.0]], $this->refunds);

        Tenant::run($shop, function (): void {
            $rows = PaymentLedger::query()->orderBy('id')->get();
            $this->assertEqualsWithDelta(0.0, (float) $rows[0]->refunded_amount, 0.001);
            $this->assertEqualsWithDelta(30.0, (float) $rows[1]->refunded_amount, 0.001);
            $this->assertSame(PlanStatus::ACTIVE, InstallmentPlan::query()->sole()->status);

            // The credit note credits the checkout's one document, for what went back.
            $credit = IssuedDocument::query()->where('context', 'refund')->sole();
            $this->assertEqualsWithDelta(30.0, (float) $credit->amount, 0.001);
        });

        $this->assertSame('gi-1', $this->issued[1]->linkedDocumentId);
    }

    public function test_the_whole_order_can_go_back_and_never_more_than_was_paid(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        $this->paidCheckout($shop, $key, $secret);

        $this->signedPost($key, $secret, '/api/woocommerce/orders/'.self::ORDER.'/refund', ['amount' => 211.0])
            ->assertStatus(422);
        $this->assertSame([], $this->refunds);

        $this->signedPost($key, $secret, '/api/woocommerce/orders/'.self::ORDER.'/refund', ['amount' => 210.0])->assertOk();
        $this->assertEqualsWithDelta(210.0, array_sum(array_column($this->refunds, 'amount')), 0.001);
    }

    public function test_the_document_gives_the_thank_you_page_its_first_look(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        $this->activeOffer($shop);
        Queue::fake();

        $this->paidCheckout($shop, $key, $secret);

        Queue::assertPushed(IssueDocumentJob::class, 1);
        // Five minutes — the merchant's rule — so an offer taken meanwhile joins it.
        Queue::assertPushed(IssueDocumentJob::class, static fn (IssueDocumentJob $job): bool => $job->delay !== null
            && abs(now()->diffInSeconds($job->delay) - OrderDocumentHold::FIRST_LOOK_SECONDS) <= 5);
        $this->assertSame(300, OrderDocumentHold::FIRST_LOOK_SECONDS);
    }

    public function test_an_offer_taken_at_once_waits_past_the_orders_five_minutes(): void
    {
        [$shop] = $this->connectedShop(scope: MerchantInvoicingSettings::SCOPE_PLANS_ONLY);
        $offer = $this->activeOffer($shop);
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $shop->getKey(), 'plan_kind' => 'recurring', 'charge_context' => 'recurring',
            'total_amount' => 100, 'installment_amount' => 100, 'currency' => 'ILS',
            'public_id' => (string) Str::ulid(), 'shopify_order_id' => self::ORDER,
            'status' => PlanStatus::ACTIVE->value,
        ])->save();
        // Shown and closed already — a short window would settle long before five minutes.
        $this->shownAndWindowClosed($shop, $offer);
        $charge = $this->offerCharge($shop, $offer, 40.0);

        $wait = app(OrderDocumentHold::class)->secondsUpsellShouldWait((int) $shop->getKey(), $charge);

        $this->assertGreaterThanOrEqual(OrderDocumentHold::FIRST_LOOK_SECONDS, $wait);
    }

    public function test_a_shop_with_no_offers_issues_the_document_at_once(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        Queue::fake();

        $this->paidCheckout($shop, $key, $secret);

        Queue::assertPushed(IssueDocumentJob::class, static fn (IssueDocumentJob $job): bool => $job->delay === null);
    }

    public function test_what_the_shopper_adds_after_checkout_joins_the_one_document_on_all_orders(): void
    {
        $this->assertOfferJoinsTheDocument(MerchantInvoicingSettings::SCOPE_ALL_ORDERS);
    }

    public function test_what_the_shopper_adds_after_checkout_joins_the_one_document_on_plans_only(): void
    {
        $this->assertOfferJoinsTheDocument(MerchantInvoicingSettings::SCOPE_PLANS_ONLY);
    }

    private function assertOfferJoinsTheDocument(string $scope): void
    {
        [$shop, $key, $secret] = $this->connectedShop(scope: $scope);
        $offer = $this->activeOffer($shop);
        Queue::fake();
        $this->paidCheckout($shop, $key, $secret);
        $orderJob = Queue::pushed(IssueDocumentJob::class)->first();

        $this->shownAndWindowClosed($shop, $offer);
        $charge = $this->offerCharge($shop, $offer, 40.0);

        $this->runJob($orderJob, $shop);
        $this->runJob(new IssueDocumentJob((int) $shop->getKey(), DocumentContext::UPSELL->value, ledgerId: (int) $charge->getKey(), itemTitle: 'Offer'), $shop);

        $this->assertCount(1, $this->issued, 'One visit, one document.');
        $this->assertEqualsWithDelta(250.0, $this->issued[0]->amount, 0.001);
        $this->assertSame(
            ['Subscription:100', 'Book:80', 'משלוח:30', 'Second book:40'],
            array_map(static fn (DocumentLine $l): string => $l->description.':'.(int) $l->total(), $this->issued[0]->lines),
        );
    }

    /** The coupon's money never moved, so it can never be asked of PayPlus. */
    public function test_a_coupon_order_is_held_to_what_was_actually_paid(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        $this->paidCheckout($shop, $key, $secret, total: 80.0, book: 0.0, shipping: 30.0, subscriptionLine: 50.0);

        Tenant::run($shop, function (): void {
            $this->assertEqualsWithDelta(80.0, (float) PaymentLedger::query()->sole()->amount, 0.001);
        });
        $this->assertEqualsWithDelta(80.0, $this->issued[0]->amount, 0.001);

        $this->signedPost($key, $secret, '/api/woocommerce/orders/'.self::ORDER.'/refund', ['amount' => 100.0])
            ->assertStatus(422);
        $this->assertSame([], $this->refunds);

        $this->signedPost($key, $secret, '/api/woocommerce/orders/'.self::ORDER.'/refund', ['amount' => 80.0])->assertOk();
        $this->assertSame([['uid' => self::TRANSACTION, 'amount' => 80.0]], $this->refunds);
    }

    public function test_a_subscription_checkouts_document_job_does_not_share_a_plugin_reports_lock(): void
    {
        $plugin = new IssueDocumentJob(shopId: 1, context: 'platform_order', order: ['order_id' => '1']);
        $ours = new IssueDocumentJob(shopId: 1, context: 'platform_order', order: ['order_id' => '1', DocumentIssuer::ORDER_PLAN_CHECKOUT => ['plan_id' => 1]]);

        $this->assertNotSame($plugin->uniqueId(), $ours->uniqueId());
    }

    /** It can go back up to what its document declared, and it gets no row its document does not hold. */
    public function test_an_order_paid_before_this_fix_gets_its_transaction_back_and_refunds_up_to_its_document(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        $this->paidCheckout($shop, $key, $secret);
        $this->forgetWhatTheFixRecorded($shop);

        $this->signedPost($key, $secret, '/api/woocommerce/orders/'.self::ORDER.'/refund', ['amount' => 150.0])
            ->assertStatus(422);
        $this->assertSame([], $this->refunds);

        $this->signedPost($key, $secret, '/api/woocommerce/orders/'.self::ORDER.'/refund', ['amount' => 30.0])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame([['uid' => self::TRANSACTION, 'amount' => 30.0]], $this->refunds);

        Tenant::run($shop, function (): void {
            $this->assertSame(0, PaymentLedger::query()->where('charge_context', PaymentLedger::CONTEXT_GATEWAY)->count());
            $this->assertEqualsWithDelta(30.0, (float) PaymentLedger::query()->sole()->refunded_amount, 0.001);
        });
    }

    /** The bug itself: answering yes here wrote a refund on the order and returned nothing. */
    public function test_a_refund_with_no_transaction_to_reverse_is_refused_rather_than_reported_as_done(): void
    {
        [$shop, $key, $secret] = $this->connectedShop();
        $this->paidCheckout($shop, $key, $secret, transactionOnOrder: false);
        $this->forgetWhatTheFixRecorded($shop);

        $this->signedPost($key, $secret, '/api/woocommerce/orders/'.self::ORDER.'/refund', ['amount' => 30.0])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('refunded', 0)
            ->assertJsonPath('reason', 'no_charge_to_refund');

        $this->assertSame([], $this->refunds);
    }

    // === Helpers ===

    private function activeOffer(Shop $shop): UpsellFlowOffer
    {
        return Tenant::run($shop, function () use ($shop): UpsellFlowOffer {
            $flow = new UpsellFlow(['name' => 'Books', 'priority' => 1]);
            $flow->shop_id = $shop->id;
            $flow->forceFill(['status' => UpsellFlowStatus::ACTIVE->value])->save();

            return UpsellFlowOffer::create([
                'flow_id' => $flow->id,
                'offer_product_gid' => '',
                'offer_variant_gid' => '',
                'offer_title' => 'Offer',
                'base_price' => 0,
                'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
                'position' => 0,
            ]);
        });
    }

    private function shownAndWindowClosed(Shop $shop, UpsellFlowOffer $offer): void
    {
        Tenant::run($shop, fn () => UpsellOfferEvent::record([
            'shop_id' => (int) $shop->getKey(),
            'flow_id' => $offer->flow_id,
            'offer_id' => $offer->getKey(),
            'event_type' => OfferEventType::IMPRESSION,
            'parent_order_id' => self::ORDER,
            'occurred_at' => now()->subHour(),
        ]));
    }

    private function offerCharge(Shop $shop, UpsellFlowOffer $offer, float $amount): PaymentLedger
    {
        return Tenant::run($shop, function () use ($shop, $offer, $amount): PaymentLedger {
            $ledger = Ledger::transition(Ledger::open(
                shopId: (int) $shop->getKey(),
                chargeContext: PaymentLedger::CONTEXT_UPSELL,
                idempotencyKey: 'upsell:'.$shop->getKey().':'.$offer->flow_id.':'.$offer->getKey().':'.self::ORDER.':1',
                amount: $amount,
                currency: 'ILS',
                attributes: ['parent_order_id' => self::ORDER],
            ), LedgerStatus::SUCCEEDED);

            UpsellOfferEvent::record([
                'shop_id' => (int) $shop->getKey(),
                'flow_id' => $offer->flow_id,
                'offer_id' => $offer->getKey(),
                'payment_ledger_id' => $ledger->getKey(),
                'event_type' => OfferEventType::CHARGE_SUCCEEDED,
                'revenue_amount' => $amount,
                'currency' => 'ILS',
                'parent_order_id' => self::ORDER,
                'context' => ['items' => [['title' => 'Second book', 'amount' => $amount]]],
            ]);

            return $ledger;
        });
    }

    /** Run a job as the queue would, past the one attempt a document may spend waiting. */
    private function runJob(IssueDocumentJob $job, Shop $shop): void
    {
        $job->withFakeQueueInteractions();
        $job->job->attempts = 2;
        Tenant::run($shop, fn () => $job->handle(app(DocumentIssuer::class)));
    }

    /** Back to what an older order looks like: a first cycle with no transaction, and no remainder. */
    private function forgetWhatTheFixRecorded(Shop $shop): void
    {
        Tenant::run($shop, function (): void {
            PaymentLedger::query()->where('charge_context', PaymentLedger::CONTEXT_GATEWAY)->delete();
            PaymentLedger::query()->update(['payplus_transaction_uid' => null]);
        });
    }

    private function paidCheckout(Shop $shop, string $key, string $secret, float $total = 210.0, float $book = 80.0, float $shipping = 30.0, bool $transactionOnOrder = true, float $subscriptionLine = 100.0): void
    {
        Tenant::run($shop, fn () => $this->makeSubscriptionProduct($shop, '501', 100.0));
        $this->fakeGateway();
        $this->fakeProvider();

        $planId = (string) $this->signedPost($key, $secret, '/api/woocommerce/gateway/session', [
            'order_id' => self::ORDER, 'amount' => $total, 'currency' => 'ILS', 'customer_id' => '55',
            'subscription_items' => [['product_id' => '501', 'variant_id' => '501', 'quantity' => 1]],
        ])->json('subscription_plan_ids.0');

        $lines = [['name' => 'Subscription', 'product_id' => 501, 'variation_id' => 0, 'quantity' => 1, 'total' => number_format($subscriptionLine, 2, '.', ''), 'total_tax' => '0.00', 'sku' => 'SKU-501']];
        if ($book > 0) {
            $lines[] = ['name' => 'Book', 'product_id' => 777, 'variation_id' => 0, 'quantity' => 1, 'total' => number_format($book, 2, '.', ''), 'total_tax' => '0.00', 'sku' => 'BOOK'];
        }

        $meta = [['key' => 'lets_subscription_plan_ids', 'value' => [$planId]]];
        if ($transactionOnOrder) {
            $meta[] = ['key' => 'lets_payplus_transaction_uid', 'value' => self::TRANSACTION];
        }

        $order = [
            'id' => (int) self::ORDER, 'number' => self::ORDER, 'status' => 'processing', 'total' => number_format($total, 2, '.', ''),
            'currency' => 'ILS', 'customer_id' => 55,
            'billing' => ['email' => 'dana@example.com', 'first_name' => 'Dana', 'last_name' => 'Levi'],
            'line_items' => $lines,
            'shipping_lines' => $shipping > 0 ? [['method_title' => 'Flat rate', 'total' => number_format($shipping, 2, '.', ''), 'total_tax' => '0.00']] : [],
            'meta_data' => $meta,
        ];

        Http::fake([
            ...$this->payplusIpn('gw:'.self::ORDER, [
                'uid' => self::TRANSACTION, 'amount' => number_format($total, 2, '.', ''),
                'token_uid' => 'tok-1', 'customer_uid' => 'c-1', 'four_digits' => '4242',
            ]),
            '*/wp-json/wc/v3/orders/'.self::ORDER.'/refunds*' => Http::response([], 200),
            '*/wp-json/wc/v3/orders/'.self::ORDER.'*' => Http::response($order, 200),
            '*' => Http::response([], 200),
        ]);

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:'.self::ORDER, 'status_code' => '000',
        ]))->assertOk()->assertJsonPath('paid', true);
    }

    private function fakeProvider(): void
    {
        $test = $this;

        InvoiceProviderFactory::fake(fn (Shop $shop): InvoiceProvider => new class($test) implements InvoiceProvider
        {
            public function __construct(private SubscriptionOrderWithShippingTest $test) {}

            public function name(): string
            {
                return Shop::INVOICING_PROVIDER_GREEN_INVOICE;
            }

            public function testConnection(): array
            {
                return [true, null];
            }

            public function issue(IssueDocumentRequest $request): IssuedDocumentResult
            {
                $this->test->issued[] = $request;

                if ($this->test->providerRefuses) {
                    return IssuedDocumentResult::failed('rejected', 'Provider is down.');
                }

                return IssuedDocumentResult::issued(
                    documentId: 'gi-'.count($this->test->issued),
                    documentNumber: (string) (60000 + count($this->test->issued)),
                    documentUrl: 'https://morning.example/d/'.count($this->test->issued),
                    documentType: '320',
                );
            }
        });
    }

    private function fakeGateway(): void
    {
        $test = $this;
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private SubscriptionOrderWithShippingTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                $this->test->refunds[] = ['uid' => $transactionUid, 'amount' => $amount];

                return GatewayResult::fromResponse(['results' => ['status' => 'success'], 'data' => ['transaction' => ['uid' => 'refund-'.count($this->test->refunds)]]]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['page_request_uid' => 'PRU', 'payment_page_link' => 'https://pay.example/p/PRU'],
                ]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    private function makeSubscriptionProduct(Shop $shop, string $externalId, float $price): void
    {
        $product = new Product;
        $product->forceFill([
            'shop_id' => $shop->id, 'source' => Product::SOURCE_WOOCOMMERCE, 'external_id' => $externalId,
            'title' => 'Product '.$externalId, 'status' => Product::STATUS_ACTIVE, 'online_store_status' => 'published',
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'shop_id' => $shop->id, 'product_id' => $product->id, 'external_variant_id' => $externalId,
            'title' => '', 'sku' => 'SKU-'.$externalId, 'price' => $price, 'position' => 0,
        ])->save();

        $plan = new ProductSubscriptionPlan;
        $plan->fill([
            'product_id' => $product->id, 'product_variant_id' => null,
            'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION, 'plan_kind' => 'recurring',
            'plan_name' => 'Monthly', 'billing_frequency' => 'monthly', 'interval_count' => 1,
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE, 'discount_value' => 0,
            'channels' => [ProductSubscriptionPlan::CHANNEL_STOREFRONT_WIDGET], 'position' => 0,
        ]);
        $plan->forceFill(['shop_id' => $shop->id, 'status' => ProductSubscriptionPlan::STATUS_ACTIVE])->save();
    }

    /** @return array{0:Shop,1:string,2:string} */
    private function connectedShop(string $scope = MerchantInvoicingSettings::SCOPE_ALL_ORDERS): array
    {
        $domain = 'sub-ship.example.com';
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];
        $shop->woocommerce_credentials = array_merge($shop->woocommerce_credentials ?: [], [
            'base_url' => 'https://'.$domain, 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp'];
        $shop->invoicing_credentials = [
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'api_key_id' => 'key-id',
            'api_secret' => 'key-secret',
            'environment' => Shop::INVOICING_ENV_SANDBOX,
        ];
        $shop->save();

        MerchantInvoicingSettings::forShop((int) $shop->getKey())->forceFill([
            'enabled' => true,
            'scope' => $scope,
        ])->save();

        $data = (array) json_decode((string) base64_decode(strtr($result['connection_token'], '-_', '+/')), true);

        return [$shop->fresh(), (string) $data['k'], (string) $data['s']];
    }

    /** @param array<string,mixed> $body */
    private function signedPost(string $apiKey, string $apiSecret, string $path, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.$path.$json, $apiSecret, true));

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey, 'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }
}
