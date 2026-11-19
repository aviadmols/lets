<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentIssuer;
use App\Domain\Invoicing\InvoiceProviderFactory;
use App\Domain\Invoicing\IssuedDocumentResult;
use App\Domain\Invoicing\IssueDocumentRequest;
use App\Domain\Invoicing\WaitingCreditNotes;
use App\Models\InstallmentPlan;
use App\Models\IssuedDocument;
use App\Models\MerchantInvoicingSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A refund that came BEFORE its sale's invoice.
 *
 * The order's invoice waits six minutes for a thank-you offer to join it. A refund inside
 * that window asked for its credit note at once, the provider refused it (it names no
 * document), and nothing ever asked again — so the customer kept a tax invoice for money
 * they had back. Three such on one store (orders 18121, 18420, 18504). Now the sale's
 * issue sends the waiting credit note again.
 */
final class CreditNoteWaitsForSaleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<IssueDocumentRequest> what the fake provider was asked for */
    public array $requests = [];

    protected function tearDown(): void
    {
        InvoiceProviderFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_credit_note_refused_before_its_sale_follows_the_sale(): void
    {
        [$shop, $ledger] = $this->refundedSale();
        $issuer = new DocumentIssuer;

        // The refund lands first: no sale document yet, so the provider refuses it.
        $credit = $issuer->issueForLedger((int) $shop->getKey(), (int) $ledger->getKey(), DocumentContext::REFUND, amountOverride: 39.0);
        $this->assertSame(IssuedDocument::STATUS_FAILED, $credit->status);
        $this->assertSame(WaitingCreditNotes::FAILURE_CODE, $credit->failure_code);

        // The held sale issues…
        $sale = $issuer->issueForLedger((int) $shop->getKey(), (int) $ledger->getKey(), DocumentContext::RECURRING);
        $this->assertTrue($sale->isIssued());

        // …and the credit note goes out on its own, on its OWN row, naming the sale.
        $credit->refresh();
        $this->assertTrue($credit->isIssued());
        $this->assertSame('39.00', (string) $credit->amount);
        $this->assertSame(2, IssuedDocument::acrossAllTenants()->count());
        $this->assertSame($sale->provider_document_id, end($this->requests)->linkedDocumentId);
    }

    /**
     * A store-checkout sale is invoiced from the ORDER (no ledger id on its document); the
     * credit note is found by the order it shares.
     */
    public function test_an_order_invoice_releases_the_credit_note_on_the_same_order(): void
    {
        [$shop, $ledger] = $this->refundedSale(orderId: '18504');
        $issuer = new DocumentIssuer;

        $credit = $issuer->issueForLedger((int) $shop->getKey(), (int) $ledger->getKey(), DocumentContext::REFUND, amountOverride: 59.0);
        $this->assertSame(WaitingCreditNotes::FAILURE_CODE, $credit->failure_code);
        $this->assertSame('18504', (string) $credit->external_order_id);

        $sale = $this->issuedOrderSale($shop, '18504');

        $this->assertSame(1, app(WaitingCreditNotes::class)->releaseFor($sale));
        $this->assertTrue($credit->refresh()->isIssued());
    }

    /** Any OTHER failure still waits for a person: only "names no document" is sent again. */
    public function test_a_credit_note_that_failed_for_another_reason_is_left_for_a_person(): void
    {
        [$shop, $ledger] = $this->refundedSale(orderId: '18420');
        $credit = (new DocumentIssuer)->issueForLedger((int) $shop->getKey(), (int) $ledger->getKey(), DocumentContext::REFUND, amountOverride: 39.0);
        $credit->forceFill(['failure_code' => 'rejected'])->save();

        $this->assertSame(0, app(WaitingCreditNotes::class)->releaseFor($this->issuedOrderSale($shop, '18420')));
        $this->assertSame(IssuedDocument::STATUS_FAILED, $credit->refresh()->status);
    }

    /** Another order's waiting credit note is not this sale's to send. */
    public function test_a_sale_releases_only_its_own_orders_credit_notes(): void
    {
        [$shop, $ledger] = $this->refundedSale(orderId: '18420');
        $credit = (new DocumentIssuer)->issueForLedger((int) $shop->getKey(), (int) $ledger->getKey(), DocumentContext::REFUND, amountOverride: 39.0);

        $this->assertSame(0, app(WaitingCreditNotes::class)->releaseFor($this->issuedOrderSale($shop, '99999')));
        $this->assertSame(IssuedDocument::STATUS_FAILED, $credit->refresh()->status);
    }

    /** Releasing twice (two sales on one order issuing together) sends it once. */
    public function test_a_waiting_credit_note_is_released_once(): void
    {
        [$shop, $ledger] = $this->refundedSale(orderId: '18121');
        (new DocumentIssuer)->issueForLedger((int) $shop->getKey(), (int) $ledger->getKey(), DocumentContext::REFUND, amountOverride: 3.0);
        $sale = $this->issuedOrderSale($shop, '18121');

        $releaser = app(WaitingCreditNotes::class);
        $this->assertSame(1, $releaser->releaseFor($sale));
        $this->assertSame(0, $releaser->releaseFor($sale));

        $credits = collect($this->requests)->filter(fn (IssueDocumentRequest $r): bool => $r->context->isCredit());
        // One refused attempt (before the sale) and ONE real credit note.
        $this->assertCount(1, $credits->filter(fn (IssueDocumentRequest $r): bool => $r->linkedDocumentId !== null));
    }

    // === Fixtures ===

    /**
     * A shop with invoicing on, a provider that refuses an unlinked credit note the way
     * Green Invoice does, and a succeeded (then refunded) charge.
     *
     * @return array{0: Shop, 1: PaymentLedger}
     */
    private function refundedSale(?string $orderId = null): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'credit-waits.example.com',
            'name' => 'Credit Waits',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->invoicing_credentials = [
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'api_key_id' => 'key-id', 'api_secret' => 'key-secret',
            'environment' => Shop::INVOICING_ENV_SANDBOX,
        ];
        $shop->save();
        MerchantInvoicingSettings::forShop((int) $shop->getKey())->forceFill(['enabled' => true])->save();

        $this->fakeProvider();

        $ledger = Tenant::run($shop, function () use ($shop, $orderId): PaymentLedger {
            $plan = new InstallmentPlan;
            $plan->fill([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'total_amount' => 100, 'installment_amount' => 100, 'currency' => 'ILS',
                'public_id' => (string) Str::ulid(),
                'customer_name' => 'Dana Buyer', 'customer_email' => 'buyer@example.com',
                'meta' => [InstallmentPlan::META_ITEM_TITLE => 'Monthly Box'],
            ]);
            $plan->forceFill(['shop_id' => (int) $shop->getKey(), 'status' => PlanStatus::ACTIVE->value])->save();

            $ledger = Ledger::open(
                shopId: (int) $shop->getKey(),
                chargeContext: PaymentLedger::CONTEXT_RECURRING,
                idempotencyKey: 'shop:'.$shop->getKey().':plan:'.$plan->getKey().':first',
                amount: 100.0,
                currency: 'ILS',
                attributes: array_filter(['plan_id' => $plan->getKey(), 'shopify_order_id' => $orderId]),
            );

            return Ledger::transition($ledger, LedgerStatus::SUCCEEDED);
        });

        return [$shop->fresh(), $ledger];
    }

    /** The order's own (held) invoice, issued — a platform-order document with no ledger id. */
    private function issuedOrderSale(Shop $shop, string $orderId): IssuedDocument
    {
        return Tenant::run($shop, function () use ($shop, $orderId): IssuedDocument {
            $sale = new IssuedDocument;
            $sale->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'context' => DocumentContext::PLATFORM_ORDER->value,
                'idempotency_key' => DocumentIssuer::keyForPlatformOrder((int) $shop->getKey(), $orderId),
                'external_order_id' => $orderId,
                'status' => IssuedDocument::STATUS_ISSUED,
                'provider_document_id' => 'gi-order-'.$orderId,
                'document_number' => '97198',
                'amount' => 100,
                'issued_at' => now(),
            ])->save();

            return $sale;
        });
    }

    private function fakeProvider(): void
    {
        $test = $this;

        InvoiceProviderFactory::fake(fn (Shop $shop): InvoiceProvider => new class($test) implements InvoiceProvider
        {
            public function __construct(private CreditNoteWaitsForSaleTest $test) {}

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
                $this->test->requests[] = $request;

                // Green Invoice's own wall: a credit note must name what it credits.
                if ($request->context->isCredit() && ($request->linkedDocumentId ?? '') === '') {
                    return IssuedDocumentResult::failed(WaitingCreditNotes::FAILURE_CODE, 'A credit note requires the document it credits.', '330');
                }

                $n = count($this->test->requests);

                return IssuedDocumentResult::issued(
                    documentId: 'gi-'.$n,
                    documentNumber: (string) (96000 + $n),
                    documentUrl: 'https://morning.example/d/'.$n,
                    documentType: $request->context->isCredit() ? '330' : '320',
                );
            }
        });
    }
}
