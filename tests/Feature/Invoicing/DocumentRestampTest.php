<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentIssuer;
use App\Domain\Invoicing\DocumentReconciliationService;
use App\Domain\Invoicing\InvoiceProviderFactory;
use App\Domain\Invoicing\IssuedDocumentResult;
use App\Domain\Invoicing\IssueDocumentRequest;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\IssuedDocument;
use App\Models\MerchantInvoicingSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Re-sending an ISSUED document to its store order — the repair verb for a
 * document whose ORDER LINKAGE was wrong when it was issued (the recurring-cycle
 * bug filed cycle invoices against the plan's original checkout order). The two
 * assertions that make it safe to offer freely: the provider is NEVER called
 * again, and the notify carries the row's CURRENT external_order_id.
 */
final class DocumentRestampTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const NOTIFY_PATH = '/wp-json/lets-payplus/v1/notify';

    /** How many documents the fake provider was asked to create. */
    public int $providerCalls = 0;

    protected function tearDown(): void
    {
        InvoiceProviderFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_restamp_resends_the_corrected_order_without_touching_the_provider(): void
    {
        Http::fake(['*'.self::NOTIFY_PATH => Http::response(['ok' => true], 200)]);
        $shop = $this->wooShop('restamp.example.com');
        $this->fakeProvider();

        // Issue against the WRONG order (the ledger's — exactly the shop-2 bug),
        // then repair the linkage the way the production fix did: a row update.
        $ledger = $this->succeededLedger($shop, wcOrderId: '2819');
        (new IssueDocumentJob(
            shopId: (int) $shop->getKey(),
            context: DocumentContext::RECURRING->value,
            ledgerId: (int) $ledger->getKey(),
        ))->handle(new DocumentIssuer);

        $document = IssuedDocument::acrossAllTenants()->sole();
        $document->forceFill(['external_order_id' => '3192'])->save();
        $this->assertSame(1, $this->providerCalls);

        $result = (new DocumentReconciliationService)->restamp($document->fresh());

        $this->assertTrue($result['ok']);
        // The store hears about the CYCLE order now — and Green Invoice was not
        // asked for anything: one document before, one document after.
        Http::assertSent(function (HttpRequest $req): bool {
            $body = $req->data();

            return str_ends_with($req->url(), self::NOTIFY_PATH)
                && ($body['event'] ?? null) === 'document_issued'
                && ($body['order_id'] ?? null) === '3192';
        });
        $this->assertSame(1, $this->providerCalls, 'A restamp must never reach the provider.');
        $this->assertSame(1, IssuedDocument::acrossAllTenants()->count());

        // The deliberate act is auditable: who re-sent, which document, to where.
        $this->assertSame(1, Tenant::run($shop, fn (): int => ActivityEvent::query()
            ->where('kind', Timeline::KIND_DOCUMENT_RESTAMPED)
            ->where('details->external_order_id', '3192')
            ->count()));
    }

    public function test_only_an_issued_document_with_an_order_can_be_restamped(): void
    {
        Http::fake();
        $shop = $this->wooShop('restamp-refuse.example.com');
        $this->fakeProvider();

        $ledger = $this->succeededLedger($shop, wcOrderId: null);
        (new IssueDocumentJob(
            shopId: (int) $shop->getKey(),
            context: DocumentContext::RECURRING->value,
            ledgerId: (int) $ledger->getKey(),
        ))->handle(new DocumentIssuer);

        $document = IssuedDocument::acrossAllTenants()->sole();
        $service = new DocumentReconciliationService;

        // Issued, but names no order → nothing to stamp.
        $noOrder = $service->restamp($document->fresh());
        $this->assertFalse($noOrder['ok']);
        $this->assertSame(DocumentReconciliationService::NO_ORDER, $noOrder['reason']);

        // Not issued → the retry/unresolved verbs own it, not this one.
        $document->forceFill([
            'status' => IssuedDocument::STATUS_FAILED,
            'external_order_id' => '3192',
        ])->save();
        $notIssued = $service->restamp($document->fresh());
        $this->assertFalse($notIssued['ok']);
        $this->assertSame(DocumentReconciliationService::NOT_ISSUED, $notIssued['reason']);

        Http::assertNothingSent();
    }

    // === Fixtures (the LedgerDocumentNotifyTest shapes) ===

    private function fakeProvider(): void
    {
        $test = $this;
        InvoiceProviderFactory::fake(fn (Shop $shop): InvoiceProvider => new class($test) implements InvoiceProvider
        {
            public function __construct(private DocumentRestampTest $test) {}

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
                $this->test->providerCalls++;

                return IssuedDocumentResult::issued(
                    documentId: 'gi-93705',
                    documentNumber: '93705',
                    documentUrl: 'https://morning.example/d/93705',
                    documentType: '320',
                );
            }
        });
    }

    private function wooShop(string $domain): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $shop->invoicing_credentials = [
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'api_key_id' => 'key-id',
            'api_secret' => 'key-secret',
            'environment' => Shop::INVOICING_ENV_SANDBOX,
        ];
        $shop->woocommerce_credentials = [
            'base_url' => 'https://'.$domain,
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
            'wc_webhook_secret' => 'whsecret-1',
        ];
        $shop->save();

        MerchantInvoicingSettings::forShop((int) $shop->getKey())->forceFill(['enabled' => true])->save();

        return $shop->fresh();
    }

    private function succeededLedger(Shop $shop, ?string $wcOrderId): PaymentLedger
    {
        return Tenant::run($shop, function () use ($shop, $wcOrderId): PaymentLedger {
            $plan = new InstallmentPlan;
            $plan->fill([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'total_amount' => 100,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'public_id' => (string) Str::ulid(),
                'customer_name' => 'Dana Buyer',
                'customer_email' => 'buyer@example.com',
                'meta' => [InstallmentPlan::META_ITEM_TITLE => 'Monthly Coffee'],
            ]);
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => PlanStatus::ACTIVE->value,
            ])->save();

            $ledger = Ledger::open(
                shopId: (int) $shop->getKey(),
                chargeContext: PaymentLedger::CONTEXT_RECURRING,
                idempotencyKey: 'shop:'.$shop->getKey().':plan:'.$plan->getKey().':cycle:2026-07-28',
                amount: 100.0,
                currency: 'ILS',
                attributes: [
                    'plan_id' => $plan->getKey(),
                    'shopify_order_id' => $wcOrderId,
                ],
            );

            return Ledger::transition($ledger, LedgerStatus::SUCCEEDED);
        });
    }
}
