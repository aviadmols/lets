<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentCustomer;
use App\Domain\Invoicing\DocumentLine;
use App\Domain\Invoicing\GreenInvoice\GreenInvoiceClient;
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
 * THE DOCUMENT'S OWN DESCRIPTION — the field the provider's document list shows in
 * its "תיאור" column, and the one this app never sent.
 *
 * A Green Invoice document carries THREE separate pieces of text, and the bug was
 * believing there were two:
 *
 *   income[].description   what was bought           — we sent it
 *   remarks                the note on the document  — we sent it
 *   description            the document's own label  — we sent NOTHING
 *
 * So every document this app has ever issued read "—" in the merchant's own list,
 * while documents from their other systems read "הזמנה 47797". The plan documents
 * were never missing a product name; they were missing a field nobody had noticed
 * existed.
 *
 * Pinned on the WIRE, because the failure is invisible from our side: the document
 * issues perfectly, the number comes back, the URL works, and only the merchant
 * scanning their own list ever sees the gap.
 */
final class DocumentDescriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_description_reaches_the_provider(): void
    {
        $this->fakeApi();

        $this->provider()->issue($this->request(description: 'הזמנת מנוי - מנוי שיבולת פלוס'));

        $this->assertSame('הזמנת מנוי - מנוי שיבולת פלוס', $this->lastDocumentPayload()['description'] ?? null);
    }

    /**
     * It is its OWN field. A reader who conflates it with remarks or with the line
     * would "simplify" one of the three away, and the merchant's list would go
     * quiet again.
     */
    public function test_it_is_a_third_field_beside_remarks_and_the_line(): void
    {
        $this->fakeApi();

        $this->provider()->issue($this->request(
            description: 'הזמנת מנוי - מנוי שיבולת',
            remarks: 'תוכנית 01M07Q6THG4VX89TEPMARVYW9Y',
            line: 'מנוי שיבולת',
        ));

        $payload = $this->lastDocumentPayload();

        $this->assertSame('הזמנת מנוי - מנוי שיבולת', $payload['description']);
        $this->assertSame('תוכנית 01M07Q6THG4VX89TEPMARVYW9Y', $payload['remarks']);
        $this->assertSame('מנוי שיבולת', $payload['income'][0]['description']);
    }

    /**
     * Absent, not empty. An explicit empty string is not the same thing as a
     * missing field to a validating API, and there is nothing to gain from telling
     * the provider that the description is blank.
     */
    public function test_a_blank_description_is_omitted_entirely(): void
    {
        foreach ([null, '', '   '] as $blank) {
            $this->fakeApi();

            $this->provider()->issue($this->request(description: $blank));

            $this->assertArrayNotHasKey(
                'description',
                $this->lastDocumentPayload(),
                'blank: '.var_export($blank, true),
            );
        }
    }

    /**
     * Clamped at the provider boundary. This is the one field on the payload whose
     * text a MERCHANT writes (their configurable receipt line), and a provider that
     * refused an over-long string would fail the document — which on this path
     * means a customer with no tax receipt.
     */
    public function test_an_over_long_description_is_clamped_rather_than_risked(): void
    {
        $this->fakeApi();

        $this->provider()->issue($this->request(description: str_repeat('א', 400)));

        $this->assertLessThanOrEqual(255, mb_strlen((string) $this->lastDocumentPayload()['description']));
    }

    /** Nothing else on the payload moved. */
    public function test_the_rest_of_the_payload_is_unchanged(): void
    {
        $this->fakeApi();

        $this->provider()->issue($this->request(description: 'x'));

        foreach (['type', 'lang', 'currency', 'vatType', 'rounding', 'client', 'income'] as $key) {
            $this->assertArrayHasKey($key, $this->lastDocumentPayload(), $key.' went missing');
        }
    }

    // === Fixtures (the provider suite's own shape) ===

    private function fakeApi(): void
    {
        Http::fake([
            '*/account/token' => Http::response(['token' => 'jwt-token', 'expires' => time() + 3600]),
            '*/documents*' => Http::response([
                'id' => 'doc-1',
                'number' => '94175',
                'url' => ['origin' => 'https://invoices.example/94175'],
            ]),
        ]);
    }

    private function provider(): GreenInvoiceProvider
    {
        $shop = $this->shop();

        $settings = MerchantInvoicingSettings::forShop((int) $shop->getKey());
        $settings->forceFill(['enabled' => true, 'prices_include_vat' => true])->save();

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
            'shopify_domain' => 'doc-description.myshopify.com',
            'name' => 'Doc Description',
            'status' => Shop::STATUS_INSTALLED,
        ]);

        $shop->invoicing_credentials = [
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'api_key_id' => 'key-id',
            'api_secret' => 'key-secret',
        ];
        $shop->save();

        return $shop->fresh();
    }

    private function request(
        ?string $description = null,
        ?string $remarks = null,
        string $line = 'מנוי שיבולת',
    ): IssueDocumentRequest {
        return new IssueDocumentRequest(
            shop: $this->shop(),
            context: DocumentContext::RECURRING,
            customer: new DocumentCustomer(name: 'דנה לוי', email: 'dana@example.com'),
            lines: [DocumentLine::single($line, 39.0)],
            amount: 39.0,
            currency: 'ILS',
            remarks: $remarks,
            description: $description,
        );
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
