<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentIssuer;
use App\Domain\Invoicing\DocumentLine;
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
use App\Models\IssuedDocument;
use App\Models\MerchantInvoicingSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ONE DOCUMENT FOR AN ORDER AND WHAT WAS ADDED AFTER CHECKOUT.
 *
 * A shop that documents every order used to issue the order's document at payment and a
 * second one when the shopper took the thank-you offer — the books on a single line. Now the
 * order's document waits until the offers shown for the order have closed, then declares the
 * order and every product the offers sold, each on its own line.
 *
 * What must never happen: the same charge declared on two documents, a document waiting
 * forever, or an order with no offer kept waiting at all.
 */
final class OrderUpsellDocumentTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const ORDER = '3540';

    /** @var list<IssueDocumentRequest> */
    public array $issued = [];

    protected function tearDown(): void
    {
        InvoiceProviderFactory::clearFake();
        Carbon::setTestNow();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_every_offer_closes_within_five_minutes(): void
    {
        $offer = new UpsellFlowOffer;

        $this->assertSame(5, $offer->forceFill(['timer_minutes' => null])->windowMinutes(), 'no time set: five minutes, never unlimited');
        $this->assertSame(5, $offer->forceFill(['timer_minutes' => 30])->windowMinutes());
        $this->assertSame(2, $offer->forceFill(['timer_minutes' => 2])->windowMinutes());
    }

    public function test_the_order_document_waits_while_an_offer_shown_for_it_is_open(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 08:46:00'));
        [$shop, $offer] = $this->shopWithOffer();
        $this->shown($shop, $offer, Carbon::parse('2026-09-17 08:45:00'));

        $job = $this->orderJob($shop)->withFakeQueueInteractions();
        Tenant::run($shop, fn () => $job->handle(app(DocumentIssuer::class)));

        // Closes 08:50:00; the last click then has SETTLE_SECONDS to be charged.
        $job->assertReleased(4 * 60 + OrderDocumentHold::SETTLE_SECONDS);
        $this->assertSame([], $this->issued);
    }

    public function test_after_the_window_one_document_declares_the_order_and_every_book(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 08:52:00'));
        [$shop, $offer] = $this->shopWithOffer();
        $this->shown($shop, $offer, Carbon::parse('2026-09-17 08:45:00'));
        $charge = $this->charge($shop, $offer, ['מרד הגנרלים' => 3.33, 'המדינה שבדרך' => 3.33, 'מפגש עם עצמי בגיהינום' => 3.33]);

        $this->runJob($this->orderJob($shop), $shop);

        $this->assertCount(1, $this->issued);
        $request = $this->issued[0];
        $this->assertSame(
            ['המדריך למשקיע המתחיל', 'מרד הגנרלים', 'המדינה שבדרך', 'מפגש עם עצמי בגיהינום'],
            array_map(fn (DocumentLine $l): string => $l->description, $request->lines),
        );
        $this->assertEqualsWithDelta(10.99, $request->amount, 0.001, 'the total that actually moved');
        $this->assertTrue($request->totalsMatch());

        $row = IssuedDocument::acrossAllTenants()->where('context', DocumentContext::PLATFORM_ORDER->value)->firstOrFail();
        $this->assertSame([(int) $charge->getKey()], $row->source_payload[OrderDocumentHold::PAYLOAD_UPSELL_LEDGERS]);

        // The upsell's own job, when it runs: already declared — no second document.
        $this->runJob($this->upsellJob($shop, $charge), $shop);
        $this->assertCount(1, $this->issued);
    }

    public function test_an_upsell_documented_alone_is_never_declared_again_on_the_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 08:52:00'));
        [$shop, $offer] = $this->shopWithOffer();
        $this->shown($shop, $offer, Carbon::parse('2026-09-17 08:45:00'));
        $charge = $this->charge($shop, $offer, ['מרד הגנרלים' => 5.00, 'המדינה שבדרך' => 4.99]);

        // First attempt: the order's document has not been opened — give it its chance.
        $waiting = $this->upsellJob($shop, $charge)->withFakeQueueInteractions();
        Tenant::run($shop, fn () => $waiting->handle(app(DocumentIssuer::class)));
        $waiting->assertReleased(OrderDocumentHold::ORDER_DOCUMENT_SLACK_SECONDS);
        $this->assertSame([], $this->issued);

        // Next attempt, still no order document: the upsell goes out alone, a line per book.
        $this->runJob($this->upsellJob($shop, $charge), $shop, attempt: 2);
        $this->assertCount(1, $this->issued);
        $this->assertSame(['מרד הגנרלים', 'המדינה שבדרך'], array_map(fn (DocumentLine $l): string => $l->description, $this->issued[0]->lines));

        // Then the order's report is handled: the books are not declared a second time.
        $this->runJob($this->orderJob($shop), $shop);
        $this->assertCount(2, $this->issued);
        $this->assertSame(['המדריך למשקיע המתחיל'], array_map(fn (DocumentLine $l): string => $l->description, $this->issued[1]->lines));
        $this->assertEqualsWithDelta(1.00, $this->issued[1]->amount, 0.001);
    }

    public function test_an_order_no_offer_was_shown_for_is_issued_at_once(): void
    {
        [$shop] = $this->shopWithOffer();

        $this->runJob($this->orderJob($shop), $shop);

        $this->assertCount(1, $this->issued);
    }

    // === Fixtures ===

    /** @return array{0: Shop, 1: UpsellFlowOffer} */
    private function shopWithOffer(): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'books.example.com',
            'name' => 'Books',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->invoicing_credentials = [
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'api_key_id' => 'key-id',
            'api_secret' => 'key-secret',
            'environment' => Shop::INVOICING_ENV_SANDBOX,
        ];
        $shop->save();

        MerchantInvoicingSettings::forShop((int) $shop->getKey())
            ->forceFill(['enabled' => true, 'scope' => MerchantInvoicingSettings::SCOPE_ALL_ORDERS])
            ->save();

        $this->fakeProvider();

        $offer = Tenant::run($shop, function () use ($shop): UpsellFlowOffer {
            $flow = new UpsellFlow(['name' => 'Books', 'priority' => 1]);
            $flow->shop_id = $shop->id;
            $flow->forceFill(['status' => UpsellFlowStatus::ACTIVE->value])->save();

            return UpsellFlowOffer::create([
                'flow_id' => $flow->id,
                'offer_product_gid' => '',
                'offer_variant_gid' => '',
                'offer_title' => 'הצעה מיוחדת',
                'base_price' => 0,
                'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
                'position' => 0,
            ]);
        });

        return [$shop->fresh(), $offer];
    }

    private function shown(Shop $shop, UpsellFlowOffer $offer, Carbon $at): void
    {
        Tenant::run($shop, fn () => UpsellOfferEvent::record([
            'shop_id' => (int) $shop->getKey(),
            'flow_id' => $offer->flow_id,
            'offer_id' => $offer->getKey(),
            'event_type' => OfferEventType::IMPRESSION,
            'parent_order_id' => self::ORDER,
            'occurred_at' => $at,
        ]));
    }

    /** @param array<string, float> $items */
    private function charge(Shop $shop, UpsellFlowOffer $offer, array $items): PaymentLedger
    {
        return Tenant::run($shop, function () use ($shop, $offer, $items): PaymentLedger {
            $amount = round(array_sum($items), 2);
            $ledger = Ledger::open(
                shopId: (int) $shop->getKey(),
                chargeContext: PaymentLedger::CONTEXT_UPSELL,
                idempotencyKey: 'upsell:'.$shop->getKey().':'.$offer->flow_id.':'.$offer->getKey().':'.self::ORDER.':1',
                amount: $amount,
                currency: 'ILS',
                attributes: ['parent_order_id' => self::ORDER],
            );
            $ledger = Ledger::transition($ledger, LedgerStatus::SUCCEEDED);

            UpsellOfferEvent::record([
                'shop_id' => (int) $shop->getKey(),
                'flow_id' => $offer->flow_id,
                'offer_id' => $offer->getKey(),
                'payment_ledger_id' => $ledger->getKey(),
                'event_type' => OfferEventType::CHARGE_SUCCEEDED,
                'revenue_amount' => $amount,
                'currency' => 'ILS',
                'parent_order_id' => self::ORDER,
                'context' => ['items' => array_map(
                    fn (string $title, float $price): array => ['title' => $title, 'amount' => $price],
                    array_keys($items),
                    array_values($items),
                )],
            ]);

            return $ledger;
        });
    }

    private function orderJob(Shop $shop): IssueDocumentJob
    {
        return new IssueDocumentJob((int) $shop->getKey(), DocumentContext::PLATFORM_ORDER->value, order: [
            'order_id' => self::ORDER,
            'order_number' => self::ORDER,
            'total' => 1.0,
            'currency' => 'ILS',
            'customer' => ['name' => 'Dana Reader', 'email' => 'reader@example.com', 'phone' => null, 'tax_id' => null],
            'lines' => [['description' => 'המדריך למשקיע המתחיל', 'unit_price' => 1.0, 'quantity' => 1, 'catalog_number' => null]],
            'payment_gateway' => 'lets_payplus',
            'card_last4' => null,
        ]);
    }

    private function upsellJob(Shop $shop, PaymentLedger $charge): IssueDocumentJob
    {
        return new IssueDocumentJob((int) $shop->getKey(), DocumentContext::UPSELL->value, ledgerId: (int) $charge->getKey(), itemTitle: 'הצעה מיוחדת');
    }

    /** Run a job as the queue would on attempt $attempt. */
    private function runJob(IssueDocumentJob $job, Shop $shop, int $attempt = 2): void
    {
        $job->withFakeQueueInteractions();
        $job->job->attempts = $attempt; // past the one attempt a document may spend waiting
        Tenant::run($shop, fn () => $job->handle(app(DocumentIssuer::class)));
    }

    private function fakeProvider(): void
    {
        $test = $this;

        InvoiceProviderFactory::fake(fn (Shop $shop): InvoiceProvider => new class($test) implements InvoiceProvider
        {
            public function __construct(private OrderUpsellDocumentTest $test) {}

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
                $n = count($this->test->issued);

                return IssuedDocumentResult::issued(documentId: 'gi-'.$n, documentNumber: (string) (94944 + $n), documentUrl: 'https://morning.example/d/'.$n, documentType: '320');
            }
        });
    }
}
