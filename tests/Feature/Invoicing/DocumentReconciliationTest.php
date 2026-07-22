<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentReconciliationService;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Filament\Resources\IssuedDocumentResource;
use App\Models\ActivityEvent;
use App\Models\IssuedDocument;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The human half of the document safety design.
 *
 * DocumentIssuer refuses to re-post an attempt whose outcome it never learned,
 * on the grounds that a person can finish the job. These tests are what make
 * that grounds true: they prove the person has a surface, that the safe action
 * is safe, and — most importantly — that the ONE action which can duplicate a
 * tax document cannot be reached without a deliberate human assertion.
 */
final class DocumentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_definitively_rejected_document_can_be_retried(): void
    {
        Queue::fake();
        [$shop, $document] = $this->documentWith(IssuedDocument::STATUS_FAILED, 'rejected');

        $result = $this->service()->retry($document);

        $this->assertTrue($result['ok']);
        // Re-opened cleanly: attempted_at MUST be cleared, or the re-queued job
        // would see an in-flight attempt and promote the row straight back to
        // unresolved — a retry that silently does nothing.
        $fresh = $document->fresh();
        $this->assertSame(IssuedDocument::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->attempted_at);
        $this->assertNull($fresh->failure_code);

        Queue::assertPushed(IssueDocumentJob::class);
    }

    public function test_an_unresolved_document_cannot_be_retried_by_the_safe_action(): void
    {
        Queue::fake();
        [$shop, $document] = $this->documentWith(IssuedDocument::STATUS_UNRESOLVED, 'outcome_unknown');

        // This is the wall. `retry` is the low-friction button; it must refuse a
        // row whose outcome is unknown, because a document may already exist.
        $result = $this->service()->retry($document);

        $this->assertFalse($result['ok']);
        $this->assertSame(DocumentReconciliationService::NOT_RETRYABLE, $result['reason']);
        $this->assertSame(IssuedDocument::STATUS_UNRESOLVED, $document->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_transport_failure_cannot_be_retried_by_the_safe_action(): void
    {
        Queue::fake();
        // The document POST itself timed out — it may well have created a
        // document. Not the same as an outright rejection.
        [$shop, $document] = $this->documentWith(IssuedDocument::STATUS_FAILED, 'transport');

        $result = $this->service()->retry($document);

        $this->assertFalse($result['ok']);
        Queue::assertNothingPushed();
    }

    public function test_issuing_after_verifying_records_the_human_assertion(): void
    {
        Queue::fake();
        [$shop, $document] = $this->documentWith(IssuedDocument::STATUS_UNRESOLVED, 'outcome_unknown');

        $result = $this->service()->issueAfterVerifying($document);

        $this->assertTrue($result['ok']);
        Queue::assertPushed(IssueDocumentJob::class);

        // The audit trail must record that a HUMAN asserted no document existed —
        // not merely that a re-issue happened. Months later, "who decided this and
        // on what basis" has to be answerable.
        // Its OWN Timeline kind, not a variant of `document_retried`: the single act
        // that can mint a duplicate tax document must be greppable on its own.
        $event = ActivityEvent::acrossAllTenants()
            ->where('kind', Timeline::KIND_DOCUMENT_FORCE_ISSUED)
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue($event->details['verified_absent_by_merchant'] ?? false);
    }

    public function test_a_store_order_is_reissued_with_its_real_payment_means(): void
    {
        Queue::fake();
        $shop = $this->shop();

        // A cash-on-delivery order. If the re-issue invented the gateway, the
        // provider would read "no gateway" as CARD and declare cash-on-delivery as
        // a credit-card payment on a tax document.
        $document = Tenant::run($shop, fn (): IssuedDocument => $this->makeDocument(
            $shop,
            IssuedDocument::STATUS_FAILED,
            'rejected',
            context: DocumentContext::PLATFORM_ORDER,
            ledgerId: null,
            sourcePayload: [
                'order_id' => '9001',
                'order_number' => '#9001',
                'total' => 100.0,
                'currency' => 'ILS',
                'customer' => ['name' => 'Dana Buyer', 'email' => 'buyer@example.com', 'phone' => null, 'tax_id' => null],
                'lines' => [['description' => 'Mug', 'unit_price' => 100.0, 'quantity' => 1, 'catalog_number' => null]],
                'payment_gateway' => 'cod',
                'card_last4' => null,
            ],
        ));

        $this->assertTrue($this->service()->retry($document)['ok']);

        Queue::assertPushed(IssueDocumentJob::class, function (IssueDocumentJob $job): bool {
            return $job->order['payment_gateway'] === 'cod'
                && $job->order['customer']['name'] === 'Dana Buyer';
        });
    }

    public function test_a_store_order_with_no_surviving_report_cannot_be_reissued(): void
    {
        Queue::fake();
        $shop = $this->shop();

        // A row from before the report was kept, or one a redaction request wiped.
        // Nothing faithful can be sent, and a document that misstates how the money
        // arrived is worse than a missing one — so refuse rather than invent.
        $document = Tenant::run($shop, fn (): IssuedDocument => $this->makeDocument(
            $shop,
            IssuedDocument::STATUS_FAILED,
            'rejected',
            context: DocumentContext::PLATFORM_ORDER,
            ledgerId: null,
            sourcePayload: null,
        ));

        $result = $this->service()->retry($document);

        $this->assertFalse($result['ok']);
        $this->assertSame(DocumentReconciliationService::NOT_REBUILDABLE, $result['reason']);
        Queue::assertNothingPushed();
    }

    public function test_recording_an_existing_document_closes_the_row_without_issuing(): void
    {
        Queue::fake();
        [$shop, $document] = $this->documentWith(IssuedDocument::STATUS_UNRESOLVED, 'outcome_unknown');

        $result = $this->service()->recordExisting($document, 'gi-4477', '60123', 'https://morning.example/d/4477');

        $this->assertTrue($result['ok']);

        $fresh = $document->fresh();
        $this->assertSame(IssuedDocument::STATUS_ISSUED, $fresh->status);
        $this->assertSame('gi-4477', $fresh->provider_document_id);
        $this->assertSame('60123', $fresh->document_number);
        $this->assertNotNull($fresh->issued_at);

        // Adopting a document must NEVER also ask the provider for another one.
        Queue::assertNothingPushed();
    }

    public function test_an_already_issued_document_is_immutable_to_every_action(): void
    {
        Queue::fake();
        [$shop, $document] = $this->documentWith(IssuedDocument::STATUS_ISSUED, null);

        foreach ([
            fn () => $this->service()->retry($document),
            fn () => $this->service()->issueAfterVerifying($document),
            fn () => $this->service()->recordExisting($document, 'gi-other'),
        ] as $attempt) {
            $result = $attempt();
            $this->assertFalse($result['ok']);
            $this->assertSame(DocumentReconciliationService::ALREADY_ISSUED, $result['reason']);
        }

        Queue::assertNothingPushed();
    }

    public function test_recording_without_a_document_id_is_refused(): void
    {
        [$shop, $document] = $this->documentWith(IssuedDocument::STATUS_UNRESOLVED, 'outcome_unknown');

        $result = $this->service()->recordExisting($document, '   ');

        $this->assertFalse($result['ok']);
        $this->assertSame(DocumentReconciliationService::MISSING_DOCUMENT_ID, $result['reason']);
        $this->assertSame(IssuedDocument::STATUS_UNRESOLVED, $document->fresh()->status);
    }

    public function test_a_credit_note_retry_carries_its_amount_so_it_reuses_the_same_row(): void
    {
        Queue::fake();
        [$shop, $document] = $this->documentWith(
            IssuedDocument::STATUS_FAILED,
            'rejected',
            context: DocumentContext::REFUND,
            amount: 42.50,
        );

        $this->service()->retry($document);

        // A credit note's idempotency key includes the amount. Re-queueing without
        // it would open a SECOND document row instead of reusing this one.
        Queue::assertPushed(
            IssueDocumentJob::class,
            fn (IssueDocumentJob $job): bool => $job->amount === 42.50,
        );
    }

    public function test_the_screen_surfaces_exactly_the_rows_that_need_a_human(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            foreach ([
                IssuedDocument::STATUS_ISSUED,
                IssuedDocument::STATUS_PENDING,
                IssuedDocument::STATUS_FAILED,
                IssuedDocument::STATUS_UNRESOLVED,
            ] as $i => $status) {
                $this->makeDocument($shop, $status, null, key: 'doc:k'.$i);
            }

            // The nav badge is the only thing that tells a merchant who never opens
            // this screen that paperwork is missing.
            $this->assertSame('2', IssuedDocumentResource::getNavigationBadge());

            $this->assertSame(2, IssuedDocument::query()
                ->whereIn('status', IssuedDocumentResource::NEEDS_ATTENTION)
                ->count());
        });
    }

    // === Helpers ===

    private function service(): DocumentReconciliationService
    {
        return new DocumentReconciliationService();
    }

    /** @return array{0:Shop,1:IssuedDocument} */
    private function documentWith(
        string $status,
        ?string $failureCode,
        DocumentContext $context = DocumentContext::RECURRING,
        float $amount = 100.0,
    ): array {
        $shop = $this->shop();

        $document = Tenant::run($shop, fn (): IssuedDocument => $this->makeDocument(
            $shop,
            $status,
            $failureCode,
            context: $context,
            amount: $amount,
        ));

        return [$shop, $document];
    }

    private function makeDocument(
        Shop $shop,
        string $status,
        ?string $failureCode,
        DocumentContext $context = DocumentContext::RECURRING,
        float $amount = 100.0,
        string $key = 'doc:test-1',
        ?int $ledgerId = 1,
        ?array $sourcePayload = null,
    ): IssuedDocument {
        $document = new IssuedDocument();
        $document->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'context' => $context->value,
            'idempotency_key' => $key,
            'document_type' => '320',
            'status' => $status,
            'amount' => $amount,
            'currency' => 'ILS',
            'failure_code' => $failureCode,
            'failure_message' => $failureCode !== null ? 'Simulated.' : null,
            'attempted_at' => $status === IssuedDocument::STATUS_UNRESOLVED ? now() : null,
            'ledger_id' => $ledgerId,
            'external_order_id' => $sourcePayload['order_id'] ?? null,
            'source_payload' => $sourcePayload,
        ])->save();

        return $document;
    }

    private function shop(): Shop
    {
        return Shop::query()->first() ?? Shop::create([
            'shopify_domain' => 'reconcile.myshopify.com',
            'name' => 'Reconcile',
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }
}
