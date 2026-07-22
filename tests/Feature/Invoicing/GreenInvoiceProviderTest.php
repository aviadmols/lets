<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentCustomer;
use App\Domain\Invoicing\DocumentLine;
use App\Domain\Invoicing\GreenInvoice\GreenInvoiceClient;
use App\Domain\Invoicing\GreenInvoice\GreenInvoiceDocumentType;
use App\Domain\Invoicing\GreenInvoice\GreenInvoiceProvider;
use App\Domain\Invoicing\IssueDocumentRequest;
use App\Models\MerchantInvoicingSettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Green Invoice provider's wire contract. Every assertion here is about a real
 * accounting consequence, not a shape: a document that totals the wrong money, a
 * receipt with no payment, or an unlinked credit note are all things a merchant must
 * later correct with the tax authority.
 */
final class GreenInvoiceProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_access_token_is_obtained_once_and_reused(): void
    {
        $this->fakeApi();

        $provider = $this->provider();
        $provider->issue($this->request());
        $provider->issue($this->request(amount: 50.0));

        // Two documents, ONE token request: the JWT is cached per shop.
        $this->assertSame(1, $this->callsTo('*/account/token'));
        $this->assertSame(2, $this->callsTo('*/documents'));
    }

    public function test_a_paid_document_carries_a_payment_row_totalling_the_money(): void
    {
        $this->fakeApi();

        // recurring → 320 (tax invoice + receipt), which the API rejects without payment[].
        $this->provider()->issue($this->request(context: DocumentContext::RECURRING, amount: 120.0));

        $payload = $this->lastDocumentPayload();

        $this->assertSame(GreenInvoiceDocumentType::TAX_INVOICE_RECEIPT->value, $payload['type']);
        $this->assertCount(1, $payload['payment']);
        $this->assertSame(120.0, $payload['payment'][0]['price']);
    }

    public function test_a_deposit_is_a_receipt_not_a_tax_invoice(): void
    {
        $this->fakeApi();

        // A deposit is money received against an INCOMPLETE sale — declaring it as a
        // tax invoice would over-report income before the goods are owed.
        $this->provider()->issue($this->request(context: DocumentContext::DEPOSIT));

        $this->assertSame(
            GreenInvoiceDocumentType::RECEIPT->value,
            $this->lastDocumentPayload()['type'],
        );
    }

    public function test_the_income_row_carries_the_unit_price_not_the_line_total(): void
    {
        $this->fakeApi();

        // 3 × 20 = 60. Sending 60 as the unit price would bill the customer 180.
        $this->provider()->issue($this->request(
            amount: 60.0,
            lines: [new DocumentLine(description: 'Coffee', unitPrice: 20.0, quantity: 3)],
        ));

        $income = $this->lastDocumentPayload()['income'][0];

        $this->assertSame(20.0, $income['price']);
        $this->assertSame(3, $income['quantity']);
    }

    public function test_a_credit_note_without_a_linked_document_is_refused_before_any_http(): void
    {
        $this->fakeApi();

        $result = $this->provider()->issue($this->request(context: DocumentContext::REFUND));

        $this->assertFalse($result->success);
        $this->assertSame('missing_linked_document', $result->errorCode);
        // Refused locally: the provider was never called with unusable paperwork.
        $this->assertSame(0, $this->callsTo('*/documents'));
    }

    public function test_a_credit_note_links_to_the_document_it_credits(): void
    {
        $this->fakeApi();

        $this->provider()->issue($this->request(
            context: DocumentContext::REFUND,
            linkedDocumentId: 'doc-original-1',
        ));

        $payload = $this->lastDocumentPayload();

        $this->assertSame(GreenInvoiceDocumentType::CREDIT_NOTE->value, $payload['type']);
        $this->assertSame(['doc-original-1'], $payload['linkedDocumentIds']);
    }

    public function test_lines_that_do_not_total_the_money_are_refused(): void
    {
        $this->fakeApi();

        // The ledger says 100 but the lines say 40 — a document for the wrong amount
        // is an accounting error, so it must never reach the provider.
        $result = $this->provider()->issue($this->request(
            amount: 100.0,
            lines: [DocumentLine::single('Partial', 40.0)],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('totals_mismatch', $result->errorCode);
        $this->assertSame(0, $this->callsTo('*/documents'));
    }

    public function test_a_rejected_document_returns_a_masked_failure_and_never_throws(): void
    {
        Http::fake([
            '*/account/token' => Http::response(['token' => 'jwt-1', 'expires' => time() + 3600]),
            '*/documents' => Http::response(['errorCode' => 400, 'errorMessage' => 'Missing client name'], 422),
        ]);

        $result = $this->provider()->issue($this->request());

        $this->assertFalse($result->success);
        $this->assertSame(GreenInvoiceClient::REASON_REJECTED, $result->errorCode);
        $this->assertSame('Missing client name', $result->errorMessage);
    }

    public function test_the_customer_email_is_only_sent_when_the_merchant_opted_in(): void
    {
        $this->fakeApi();

        // Default settings: provider-side email is OFF, so no address is handed over.
        $this->provider()->issue($this->request());
        $this->assertArrayNotHasKey('emails', $this->lastDocumentPayload()['client']);

        $this->provider(sendEmail: true)->issue($this->request(sendEmail: true));
        $this->assertSame(['buyer@example.com'], $this->lastDocumentPayload()['client']['emails']);
    }

    public function test_test_connection_obtains_a_token_and_issues_nothing(): void
    {
        $this->fakeApi();

        [$ok, $reason] = $this->provider()->testConnection();

        $this->assertTrue($ok);
        $this->assertNull($reason);
        $this->assertSame(0, $this->callsTo('*/documents'));
    }

    // === Helpers ===

    private function fakeApi(): void
    {
        Http::fake([
            '*/account/token' => Http::response(['token' => 'jwt-1', 'expires' => time() + 3600]),
            '*/documents' => Http::response([
                'id' => 'gi-doc-1',
                'number' => '60001',
                'url' => ['origin' => 'https://morning.example/d/1', 'he' => 'https://morning.example/d/1?he'],
            ]),
        ]);
    }

    private function provider(bool $sendEmail = false): GreenInvoiceProvider
    {
        $shop = $this->shop();

        $settings = MerchantInvoicingSettings::forShop((int) $shop->getKey());
        $settings->forceFill([
            'enabled' => true,
            'send_email_to_customer' => $sendEmail,
        ])->save();

        return new GreenInvoiceProvider(
            client: new GreenInvoiceClient(
                credentials: $shop->invoicingConfig(),
                shopId: (int) $shop->getKey(),
                timeout: 5,
            ),
            settings: $settings,
        );
    }

    private function shop(): Shop
    {
        $shop = Shop::query()->first() ?? Shop::create([
            'shopify_domain' => 'gi-provider.myshopify.com',
            'name' => 'GI Provider',
            'status' => Shop::STATUS_INSTALLED,
        ]);

        $shop->invoicing_credentials = [
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'api_key_id' => 'key-id',
            'api_secret' => 'key-secret',
            'environment' => Shop::INVOICING_ENV_SANDBOX,
        ];
        $shop->save();

        return $shop->fresh();
    }

    /** @param list<DocumentLine>|null $lines */
    private function request(
        DocumentContext $context = DocumentContext::PLATFORM_ORDER,
        float $amount = 100.0,
        ?array $lines = null,
        ?string $linkedDocumentId = null,
        bool $sendEmail = false,
    ): IssueDocumentRequest {
        return new IssueDocumentRequest(
            shop: $this->shop(),
            context: $context,
            customer: new DocumentCustomer(name: 'Dana Buyer', email: 'buyer@example.com'),
            lines: $lines ?? [DocumentLine::single('Order 1001', $amount)],
            amount: $amount,
            currency: 'ILS',
            linkedDocumentId: $linkedDocumentId,
            sendEmail: $sendEmail,
        );
    }

    /** How many recorded requests hit a URL pattern. */
    private function callsTo(string $pattern): int
    {
        $needle = trim($pattern, '*');

        return Http::recorded(
            static fn (Request $request): bool => str_contains($request->url(), $needle)
        )->count();
    }

    /** @return array<string, mixed> */
    private function lastDocumentPayload(): array
    {
        $recorded = Http::recorded(
            static fn (Request $request): bool => str_contains($request->url(), '/documents')
        );

        $this->assertNotEmpty($recorded, 'No document request was sent.');

        return (array) $recorded->last()[0]->data();
    }
}
