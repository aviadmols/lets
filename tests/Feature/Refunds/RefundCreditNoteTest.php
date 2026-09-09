<?php

namespace Tests\Feature\Refunds;

use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentIssuer;
use App\Domain\Invoicing\InvoiceProviderFactory;
use App\Domain\Invoicing\IssuedDocumentResult;
use App\Domain\Invoicing\IssueDocumentRequest;
use App\Domain\Refunds\Models\RefundRequest;
use App\Domain\Refunds\RefundOrchestrator;
use App\Models\IssuedDocument;
use App\Models\MerchantInvoicingSettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The paperwork a refund leaves — issued for real, not merely queued.
 *
 * The rule these pin is the one an accountant discovers rather than we do:
 * every slice of money that goes back has to be declared, and two slices of the
 * SAME SIZE against the same sale are two declarations. Keyed on the amount
 * alone, the second ₪50 credit note resolved to the first one's document — the
 * customer had ₪100 back and the books said ₪50.
 */
final class RefundCreditNoteTest extends TestCase
{
    use MakesRefundScenarios;
    use RefreshDatabase;

    /** @var list<IssueDocumentRequest> */
    public array $issued = [];

    protected function tearDown(): void
    {
        InvoiceProviderFactory::clearFake();
        $this->clearRefundFakes();
        parent::tearDown();
    }

    public function test_two_equal_slices_produce_two_credit_notes(): void
    {
        $shop = $this->invoicingShop();
        $this->fakeGateway();
        $this->fakeStore();
        $this->fakeProvider();

        $this->makeCharge($shop, orderId: '4001', amount: 200.00, uid: 'txn-sale');

        $input = ['mode' => RefundRequest::MODE_REFUND_PARTIAL, 'order_id' => '4001', 'amount' => 50.00];
        $this->refund($shop, $input);
        $this->refund($shop, $input);

        $this->assertCount(2, $this->issued, 'Both slices are declared.');
        $this->assertSame([50.0, 50.0], array_map(
            static fn (IssueDocumentRequest $r): float => round($r->amount, 2),
            $this->issued,
        ));

        Tenant::run($shop, function (): void {
            $credits = IssuedDocument::query()
                ->where('context', DocumentContext::REFUND->value)
                ->get();

            $this->assertCount(2, $credits);
            $this->assertCount(
                2,
                $credits->pluck('idempotency_key')->unique(),
                'Two documents means two keys — the second must not resolve to the first.',
            );
        });
    }

    public function test_a_credit_note_names_the_request_that_made_it(): void
    {
        $shop = $this->invoicingShop();
        $this->fakeGateway();
        $this->fakeStore();
        $this->fakeProvider();

        $this->makeCharge($shop, orderId: '4002', amount: 100.00, uid: 'txn-sale');

        $request = $this->refund($shop, [
            'mode' => RefundRequest::MODE_REFUND_FULL,
            'order_id' => '4002',
        ]);

        Tenant::run($shop, function () use ($request): void {
            $credit = IssuedDocument::query()
                ->where('refund_request_id', (int) $request->getKey())
                ->first();

            $this->assertNotNull($credit, 'The accountant can walk back to the decision.');
            $this->assertSame(DocumentContext::REFUND->value, $credit->context);
        });
    }

    /**
     * The credit note is linked to the SALE it credits. Without the link Green
     * Invoice refuses a 330 outright, so this is what makes the document legal
     * rather than merely present.
     */
    public function test_the_credit_note_is_linked_to_the_document_it_credits(): void
    {
        $shop = $this->invoicingShop();
        $this->fakeGateway();
        $this->fakeStore();
        $this->fakeProvider();

        $charge = $this->makeCharge($shop, orderId: '4003', amount: 100.00, uid: 'txn-sale');

        // The sale's own document, exactly as the charge path would have issued it.
        Tenant::run($shop, fn () => app(DocumentIssuer::class)->issueForLedger(
            (int) $shop->getKey(),
            (int) $charge->getKey(),
            DocumentContext::RECURRING,
        ));

        $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '4003']);

        $credit = collect($this->issued)->first(
            static fn (IssueDocumentRequest $r): bool => $r->context->isCredit(),
        );

        $this->assertNotNull($credit);
        $this->assertNotNull($credit->linkedDocumentId, 'A credit note must name the sale it reverses.');
    }

    public function test_a_shop_without_invoicing_still_refunds_and_issues_nothing(): void
    {
        $shop = $this->makeShop();
        $this->fakeGateway();
        $this->fakeStore();
        $this->fakeProvider();

        $this->makeCharge($shop, orderId: '4004', amount: 100.00, uid: 'txn-sale');

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '4004']);

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(100.00, $request->refundedTotal());
        $this->assertSame([], $this->issued, 'No provider opted in, so no paperwork — and no error.');
    }

    // === Helpers ===

    /** @param array<string, mixed> $input */
    private function refund(Shop $shop, array $input): RefundRequest
    {
        $orchestrator = app(RefundOrchestrator::class);

        return $orchestrator->run($shop, $orchestrator->open($shop, $input));
    }

    private function invoicingShop(): Shop
    {
        $shop = $this->makeShop();

        $shop->invoicing_credentials = [
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'api_key_id' => 'key-id',
            'api_secret' => 'key-secret',
            'environment' => Shop::INVOICING_ENV_SANDBOX,
        ];
        $shop->save();

        MerchantInvoicingSettings::forShop((int) $shop->getKey())->forceFill(['enabled' => true])->save();

        return $shop->fresh();
    }

    private function fakeProvider(): void
    {
        $test = $this;

        InvoiceProviderFactory::fake(fn (Shop $shop): InvoiceProvider => new class($test) implements InvoiceProvider
        {
            public function __construct(private RefundCreditNoteTest $test) {}

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

                return IssuedDocumentResult::issued(
                    documentId: 'gi-'.count($this->test->issued),
                    documentNumber: (string) (70000 + count($this->test->issued)),
                    documentUrl: 'https://morning.example/d/'.count($this->test->issued),
                    documentType: '330',
                );
            }
        });
    }
}
