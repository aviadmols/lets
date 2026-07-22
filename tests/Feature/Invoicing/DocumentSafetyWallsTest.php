<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentIssuer;
use App\Domain\Invoicing\InvoiceProviderFactory;
use App\Domain\Invoicing\IssueDocumentRequest;
use App\Domain\Invoicing\IssuedDocumentResult;
use App\Jobs\Privacy\RedactCustomerData;
use App\Jobs\Privacy\RedactShopData;
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
 * The four walls that stop the invoicing module producing WRONG paperwork, each of
 * which costs a merchant a correction with the tax authority rather than a bug fix:
 *
 *   1. the central DocumentPolicy governs EVERY path, not just the charge pipeline;
 *   2. a credit note credits the sale it belongs to, not "the newest one";
 *   3. an interrupted attempt is never blindly re-posted;
 *   4. a redaction request reaches the documents too.
 */
final class DocumentSafetyWallsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<IssueDocumentRequest> */
    public array $issued = [];

    protected function tearDown(): void
    {
        InvoiceProviderFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    // === Wall 1: the central DocumentPolicy governs every path ===

    public function test_document_mode_none_suppresses_documents_on_the_ledger_path(): void
    {
        $shop = $this->connectedShop('policy.myshopify.com');
        $this->fakeProvider();

        $ledger = $this->succeededLedger($shop, documentMode: 'none');

        $result = (new DocumentIssuer())->issueForLedger(
            (int) $shop->getKey(),
            (int) $ledger->getKey(),
            DocumentContext::RECURRING,
        );

        // The merchant told the policy "issue nothing". Every hook must honour that —
        // a switch obeyed on some paths is not a switch.
        $this->assertNull($result);
        $this->assertCount(0, $this->issued);
    }

    public function test_a_policy_that_issues_nothing_also_suppresses_plain_store_orders(): void
    {
        $shop = $this->connectedShop('policy-order.example.com', Shop::PLATFORM_WOOCOMMERCE);
        $this->fakeProvider();

        // A platform with no tax-invoice type configured: the policy answers "issue
        // nothing", and the all_orders path must respect that rather than route
        // around it — the gate is consulted on BOTH entry points, not just the
        // ledger one.
        config()->set('payplus.document_types.tax_invoice', null);

        $result = (new DocumentIssuer())->issueForPlatformOrder((int) $shop->getKey(), $this->orderPayload());

        $this->assertNull($result);
        $this->assertCount(0, $this->issued);
    }

    // === Wall 2: a credit note credits the right document ===

    public function test_a_credit_note_links_to_the_document_for_the_charge_being_refunded(): void
    {
        $shop = $this->connectedShop('credit-link.myshopify.com');
        $this->fakeProvider();

        $shopId = (int) $shop->getKey();
        $issuer = new DocumentIssuer();

        // Two charges on ONE plan: an early slice, then a later one.
        $first = $this->succeededLedger($shop, key: 'cycle-1');
        $second = $this->succeededLedger($shop, key: 'cycle-2', plan: $this->planOf($first));

        $firstDoc = $issuer->issueForLedger($shopId, (int) $first->getKey(), DocumentContext::RECURRING);
        $issuer->issueForLedger($shopId, (int) $second->getKey(), DocumentContext::RECURRING);

        // Refund the FIRST charge. The credit note must reference the FIRST document —
        // "the newest document on the plan" would credit it against the second sale,
        // and if that sale is smaller the credit exceeds what it credits.
        $issuer->issueForLedger($shopId, (int) $first->getKey(), DocumentContext::REFUND, amountOverride: 100.0);

        /** @var IssueDocumentRequest $creditRequest */
        $creditRequest = end($this->issued);

        $this->assertSame($firstDoc?->provider_document_id, $creditRequest->linkedDocumentId);
    }

    public function test_a_mid_stream_receipt_is_not_chained_to_its_predecessor(): void
    {
        $shop = $this->connectedShop('nochain.myshopify.com');
        $this->fakeProvider();

        $shopId = (int) $shop->getKey();
        $issuer = new DocumentIssuer();

        $first = $this->succeededLedger($shop, key: 'slice-1');
        $second = $this->succeededLedger($shop, key: 'slice-2', plan: $this->planOf($first));

        $issuer->issueForLedger($shopId, (int) $first->getKey(), DocumentContext::INSTALLMENT);
        $issuer->issueForLedger($shopId, (int) $second->getKey(), DocumentContext::INSTALLMENT);

        // The policy links only the FINAL installment (it ties the plan together).
        // A chain of every receipt to its predecessor is a structure nobody asked for.
        /** @var IssueDocumentRequest $secondRequest */
        $secondRequest = end($this->issued);
        $this->assertNull($secondRequest->linkedDocumentId);
    }

    // === Wall 3: an interrupted attempt is never blindly re-posted ===

    public function test_an_interrupted_attempt_is_never_reposted(): void
    {
        $shop = $this->connectedShop('interrupted.myshopify.com');
        $this->fakeProvider();

        $shopId = (int) $shop->getKey();
        $ledger = $this->succeededLedger($shop);
        $issuer = new DocumentIssuer();

        // Simulate a worker killed between the provider accepting the document and our
        // write: the row is left `pending` WITH an attempt stamped on it.
        $issuer->issueForLedger($shopId, (int) $ledger->getKey(), DocumentContext::RECURRING);
        $row = IssuedDocument::acrossAllTenants()->firstOrFail();
        $row->forceFill([
            'status' => IssuedDocument::STATUS_PENDING,
            'attempted_at' => now(),
            'provider_document_id' => null,
        ])->save();

        $this->issued = [];

        // The redelivered job must NOT call the provider — Green Invoice has no
        // idempotency key, so a second POST is a second REAL tax document.
        $retried = $issuer->issueForLedger($shopId, (int) $ledger->getKey(), DocumentContext::RECURRING);

        $this->assertCount(0, $this->issued);
        $this->assertSame(IssuedDocument::STATUS_UNRESOLVED, $retried?->status);
        $this->assertSame('outcome_unknown', $retried->failure_code);
    }

    public function test_a_transport_failure_is_not_retried_but_an_outright_rejection_is(): void
    {
        $shop = $this->connectedShop('transport.myshopify.com');
        $shopId = (int) $shop->getKey();
        $ledger = $this->succeededLedger($shop);
        $issuer = new DocumentIssuer();

        // A transport error may mean the request DID reach the provider and created a
        // document — we never learn. Retrying would risk a duplicate.
        $this->fakeProvider(failWith: 'transport');
        $issuer->issueForLedger($shopId, (int) $ledger->getKey(), DocumentContext::RECURRING);
        $this->assertFalse(IssuedDocument::acrossAllTenants()->firstOrFail()->isRetryable());

        // An outright rejection PROVES nothing was created, so it is safe to retry.
        IssuedDocument::acrossAllTenants()->firstOrFail()->forceFill(['failure_code' => 'rejected'])->save();
        $this->assertTrue(IssuedDocument::acrossAllTenants()->firstOrFail()->isRetryable());
    }

    public function test_only_one_of_two_racing_workers_may_call_the_provider(): void
    {
        $shop = $this->connectedShop('race.myshopify.com');
        $this->fakeProvider();

        $shopId = (int) $shop->getKey();
        $ledger = $this->succeededLedger($shop);

        // ShouldBeUnique's lock expires after uniqueFor, so on a backed-up queue two
        // workers can genuinely hold the same job. Both would read attempted_at as
        // null and both would POST — two real tax documents. The claim is therefore
        // a conditional UPDATE decided by the database, not a read-then-write.
        $first = (new DocumentIssuer())->issueForLedger($shopId, (int) $ledger->getKey(), DocumentContext::RECURRING);
        $this->assertNotNull($first);

        // Simulate the loser: the row is back to pending with the claim already
        // taken, which is exactly the state the second worker would observe.
        IssuedDocument::acrossAllTenants()->firstOrFail()
            ->forceFill(['status' => IssuedDocument::STATUS_PENDING])->save();

        $callsBefore = count($this->issued);
        (new DocumentIssuer())->issueForLedger($shopId, (int) $ledger->getKey(), DocumentContext::RECURRING);

        $this->assertCount($callsBefore, $this->issued, 'The losing worker must not call the provider.');
    }

    // === Wall 4: redaction reaches the documents ===

    public function test_shop_redaction_strips_the_customer_identity_from_issued_documents(): void
    {
        $shop = $this->connectedShop('redact.myshopify.com');
        $this->fakeProvider();

        $shopId = (int) $shop->getKey();
        $ledger = $this->succeededLedger($shop);
        (new DocumentIssuer())->issueForLedger($shopId, (int) $ledger->getKey(), DocumentContext::RECURRING);

        // A document row carries a live link to a document bearing the customer's
        // name, plus the provider's echo of the client block.
        Tenant::run($shop, function (): void {
            IssuedDocument::query()->firstOrFail()->forceFill([
                'raw_response_masked' => ['client' => ['name' => 'Dana Buyer', 'email' => 'buyer@example.com']],
            ])->save();
        });

        (new RedactShopData($shopId))->handle();

        Tenant::run($shop, function (): void {
            $document = IssuedDocument::query()->firstOrFail();

            $this->assertNull($document->document_url);
            $this->assertStringNotContainsString(
                'buyer@example.com',
                (string) json_encode($document->raw_response_masked),
            );
            // The financial record survives — the same trade the ledger makes.
            $this->assertSame('100.00', (string) $document->amount);
            $this->assertSame(IssuedDocument::STATUS_ISSUED, $document->status);
        });
    }

    public function test_customer_redaction_reaches_a_plan_less_upsell_document(): void
    {
        $shop = $this->connectedShop('upsell-redact.myshopify.com');
        $this->fakeProvider();
        $shopId = (int) $shop->getKey();

        // An upsell is a charge CONTEXT, not a plan, so its ledger row — and the
        // document built from it — carry plan_id = null. Scoping redaction to the
        // customer's plans alone would leave this document, bearing their name,
        // standing after an erasure request.
        $ledger = Tenant::run($shop, function () use ($shop): PaymentLedger {
            $row = Ledger::open(
                shopId: (int) $shop->getKey(),
                chargeContext: PaymentLedger::CONTEXT_UPSELL,
                idempotencyKey: 'shop:'.$shop->getKey().':upsell:1',
                amount: 49.0,
                currency: 'ILS',
                attributes: ['plan_id' => null, 'shopify_customer_id' => 'cust-77'],
            );

            return Ledger::transition($row, LedgerStatus::SUCCEEDED);
        });

        (new DocumentIssuer())->issueForLedger($shopId, (int) $ledger->getKey(), DocumentContext::UPSELL);

        Tenant::run($shop, function (): void {
            $document = IssuedDocument::query()->firstOrFail();
            $this->assertNull($document->plan_id, 'An upsell document must have no plan.');

            // The provider echoes the client block back, tax id and all.
            $document->forceFill([
                'raw_response_masked' => ['client' => [
                    'name' => 'Dana Buyer',
                    'taxId' => '123456782',   // an Israeli ת.ז — a national identity number
                ]],
            ])->save();
        });

        (new RedactCustomerData($shopId, ['customer' => ['id' => 'cust-77']]))->handle();

        Tenant::run($shop, function (): void {
            $document = IssuedDocument::query()->firstOrFail();
            $raw = (string) json_encode($document->raw_response_masked);

            $this->assertNull($document->document_url);
            $this->assertStringNotContainsString('Dana Buyer', $raw);
            $this->assertStringNotContainsString('123456782', $raw);
            // The financial record survives.
            $this->assertSame('49.00', (string) $document->amount);
        });
    }

    public function test_a_redacted_store_order_report_cannot_be_reissued(): void
    {
        $shop = $this->connectedShop('redacted-order.example.com', Shop::PLATFORM_WOOCOMMERCE);
        $this->fakeProvider();
        $shopId = (int) $shop->getKey();

        (new DocumentIssuer())->issueForPlatformOrder($shopId, $this->orderPayload());

        // Fail the document so a merchant would be offered the retry button.
        Tenant::run($shop, function (): void {
            IssuedDocument::query()->firstOrFail()->forceFill([
                'status' => IssuedDocument::STATUS_FAILED,
                'failure_code' => 'rejected',
            ])->save();
        });

        (new RedactShopData($shopId))->handle();

        Tenant::run($shop, function (): void {
            $document = IssuedDocument::query()->firstOrFail();

            // The report must be GONE, not merely scrubbed. A scrubbed skeleton still
            // carries order_id, so the row would look rebuildable and a retry would
            // print "[redacted]" as the client name on a real tax document.
            $this->assertNull($document->source_payload);

            $result = (new \App\Domain\Invoicing\DocumentReconciliationService())->retry($document);

            $this->assertFalse($result['ok']);
            $this->assertSame('not_rebuildable', $result['reason']);
        });
    }

    // === Helpers ===

    private function fakeProvider(?string $failWith = null): void
    {
        $test = $this;

        InvoiceProviderFactory::fake(fn (Shop $shop): InvoiceProvider => new class($test, $failWith) implements InvoiceProvider
        {
            public function __construct(private DocumentSafetyWallsTest $test, private ?string $failWith) {}

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

                if ($this->failWith !== null) {
                    return IssuedDocumentResult::failed($this->failWith, 'Simulated failure.');
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

    private function connectedShop(string $domain, string $platform = Shop::PLATFORM_SHOPIFY): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $platform === Shop::PLATFORM_SHOPIFY ? $domain : null,
            'woocommerce_domain' => $platform === Shop::PLATFORM_WOOCOMMERCE ? $domain : null,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
            'platform' => $platform,
        ]);

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

    private function planOf(PaymentLedger $ledger): InstallmentPlan
    {
        return InstallmentPlan::acrossAllTenants()->findOrFail($ledger->plan_id);
    }

    private function succeededLedger(
        Shop $shop,
        string $key = 'cycle-1',
        ?string $documentMode = null,
        ?InstallmentPlan $plan = null,
    ): PaymentLedger {
        return Tenant::run($shop, function () use ($shop, $key, $documentMode, $plan): PaymentLedger {
            if ($plan === null) {
                $plan = new InstallmentPlan;
                $plan->fill([
                    'plan_kind' => PlanKind::RECURRING->value,
                    'charge_context' => 'recurring',
                    'total_amount' => 1000,
                    'installment_amount' => 100,
                    'currency' => 'ILS',
                    'public_id' => (string) Str::ulid(),
                    'customer_name' => 'Dana Buyer',
                    'customer_email' => 'buyer@example.com',
                    'meta' => array_filter([
                        InstallmentPlan::META_ITEM_TITLE => 'Monthly Coffee',
                        'document_settings' => $documentMode !== null ? ['document_mode' => $documentMode] : null,
                    ]),
                ]);
                $plan->forceFill([
                    'shop_id' => (int) $shop->getKey(),
                    'status' => PlanStatus::ACTIVE->value,
                ])->save();
            }

            $ledger = Ledger::open(
                shopId: (int) $shop->getKey(),
                chargeContext: PaymentLedger::CONTEXT_RECURRING,
                idempotencyKey: 'shop:'.$shop->getKey().':plan:'.$plan->getKey().':'.$key,
                amount: 100.0,
                currency: 'ILS',
                attributes: ['plan_id' => $plan->getKey()],
            );

            return Ledger::transition($ledger, LedgerStatus::SUCCEEDED);
        });
    }

    /** @return array<string, mixed> */
    private function orderPayload(): array
    {
        return [
            'order_id' => '7001',
            'order_number' => '7001',
            'total' => 100.0,
            'currency' => 'ILS',
            'customer' => ['name' => 'Dana Buyer', 'email' => 'buyer@example.com', 'phone' => null, 'tax_id' => null],
            'lines' => [],
            'payment_gateway' => 'bacs',
            'card_last4' => null,
        ];
    }
}
