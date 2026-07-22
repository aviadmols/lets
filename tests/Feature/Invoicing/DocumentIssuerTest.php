<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentIssuer;
use App\Domain\Invoicing\InvoiceProviderFactory;
use App\Domain\Invoicing\IssueDocumentRequest;
use App\Domain\Invoicing\IssuedDocumentResult;
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
 * The double-issue wall and the tenant wall. A duplicated tax document is not a
 * cosmetic bug — it double-declares income the merchant then has to credit back — so
 * these are the release-blocking assertions of the invoicing module.
 */
final class DocumentIssuerTest extends TestCase
{
    use RefreshDatabase;

    /** Requests every fake provider recorded, across all shops. */
    public array $issued = [];

    protected function tearDown(): void
    {
        InvoiceProviderFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_issuing_twice_for_one_money_movement_creates_one_document(): void
    {
        $shop = $this->connectedShop('idem.myshopify.com');
        $this->fakeProvider();

        $ledger = $this->succeededLedger($shop);
        $issuer = new DocumentIssuer();

        $first = $issuer->issueForLedger((int) $shop->getKey(), (int) $ledger->getKey(), DocumentContext::RECURRING);
        $second = $issuer->issueForLedger((int) $shop->getKey(), (int) $ledger->getKey(), DocumentContext::RECURRING);

        $this->assertNotNull($first);
        $this->assertSame($first->getKey(), $second?->getKey(), 'The second call must reuse the first document.');
        $this->assertCount(1, $this->issued, 'The provider must be called exactly once.');
        $this->assertSame(1, IssuedDocument::acrossAllTenants()->count());
    }

    public function test_a_failed_document_can_be_retried_on_the_same_row(): void
    {
        $shop = $this->connectedShop('retry.myshopify.com');
        $this->fakeProvider(succeed: false);

        $ledger = $this->succeededLedger($shop);
        $issuer = new DocumentIssuer();

        $failed = $issuer->issueForLedger((int) $shop->getKey(), (int) $ledger->getKey(), DocumentContext::RECURRING);
        $this->assertSame(IssuedDocument::STATUS_FAILED, $failed?->status);

        // The provider recovers; the retry reuses the SAME row rather than opening a
        // second one — otherwise a flaky provider would leave duplicate paperwork.
        $this->fakeProvider(succeed: true);
        $retried = $issuer->issueForLedger((int) $shop->getKey(), (int) $ledger->getKey(), DocumentContext::RECURRING);

        $this->assertSame($failed->getKey(), $retried?->getKey());
        $this->assertSame(IssuedDocument::STATUS_ISSUED, $retried->status);
        $this->assertSame(1, IssuedDocument::acrossAllTenants()->count());
    }

    public function test_a_refund_is_a_separate_document_from_the_sale_it_credits(): void
    {
        $shop = $this->connectedShop('credit.myshopify.com');
        $this->fakeProvider();

        $ledger = $this->succeededLedger($shop);
        $issuer = new DocumentIssuer();
        $shopId = (int) $shop->getKey();

        $sale = $issuer->issueForLedger($shopId, (int) $ledger->getKey(), DocumentContext::RECURRING);
        $credit = $issuer->issueForLedger($shopId, (int) $ledger->getKey(), DocumentContext::REFUND, amountOverride: 40.0);

        $this->assertNotNull($sale);
        $this->assertNotNull($credit);
        $this->assertNotSame($sale->getKey(), $credit->getKey());
        // The credit note declares the PARTIAL amount, not the whole sale.
        $this->assertSame('40.00', (string) $credit->amount);
    }

    public function test_two_partial_refunds_of_one_sale_each_get_their_own_credit_note(): void
    {
        $shop = $this->connectedShop('partials.myshopify.com');
        $this->fakeProvider();

        $ledger = $this->succeededLedger($shop);
        $issuer = new DocumentIssuer();
        $shopId = (int) $shop->getKey();

        $issuer->issueForLedger($shopId, (int) $ledger->getKey(), DocumentContext::REFUND, amountOverride: 30.0);
        $issuer->issueForLedger($shopId, (int) $ledger->getKey(), DocumentContext::REFUND, amountOverride: 25.0);

        // Keying a credit note on the ledger row alone would swallow the second refund
        // and under-report it — the amount is part of the key for exactly this reason.
        $this->assertSame(2, IssuedDocument::acrossAllTenants()
            ->where('context', DocumentContext::REFUND->value)
            ->count());
    }

    public function test_a_plain_store_order_is_invoiced_once_however_often_it_is_reported(): void
    {
        $shop = $this->connectedShop('order.example.com', platform: Shop::PLATFORM_WOOCOMMERCE);
        $this->fakeProvider();

        $issuer = new DocumentIssuer();
        $shopId = (int) $shop->getKey();

        // The same order reported twice — a WooCommerce order moving processing → completed.
        $issuer->issueForPlatformOrder($shopId, $this->orderPayload());
        $issuer->issueForPlatformOrder($shopId, $this->orderPayload());

        $this->assertCount(1, $this->issued);
        $this->assertSame(1, IssuedDocument::acrossAllTenants()->count());
    }

    public function test_a_partial_line_breakdown_is_balanced_to_the_order_total(): void
    {
        $shop = $this->connectedShop('balance.example.com', platform: Shop::PLATFORM_WOOCOMMERCE);
        $this->fakeProvider();

        // Items total 80, but the order total is 95 (shipping the plugin did not itemise).
        (new DocumentIssuer())->issueForPlatformOrder((int) $shop->getKey(), $this->orderPayload(
            total: 95.0,
            lines: [['description' => 'Mug', 'unit_price' => 40.0, 'quantity' => 2, 'catalog_number' => null]],
        ));

        /** @var IssueDocumentRequest $request */
        $request = $this->issued[0];

        // The document must total the money that actually moved, or it is wrong paperwork.
        $this->assertTrue($request->totalsMatch());
        $this->assertSame(95.0, $request->lineTotal());
    }

    public function test_nothing_is_issued_when_the_merchant_has_invoicing_off(): void
    {
        $shop = $this->connectedShop('off.myshopify.com');
        $this->fakeProvider();

        // Credentials present, module OFF — the default state for every existing shop.
        MerchantInvoicingSettings::forShop((int) $shop->getKey())->forceFill(['enabled' => false])->save();

        $ledger = $this->succeededLedger($shop);
        $result = (new DocumentIssuer())->issueForLedger(
            (int) $shop->getKey(),
            (int) $ledger->getKey(),
            DocumentContext::RECURRING,
        );

        $this->assertNull($result);
        $this->assertCount(0, $this->issued);
        $this->assertSame(0, IssuedDocument::acrossAllTenants()->count());
    }

    public function test_documents_are_tenant_isolated(): void
    {
        $shopA = $this->connectedShop('iso-a.myshopify.com');
        $shopB = $this->connectedShop('iso-b.myshopify.com');
        $this->fakeProvider();

        $ledgerA = $this->succeededLedger($shopA);
        (new DocumentIssuer())->issueForLedger((int) $shopA->getKey(), (int) $ledgerA->getKey(), DocumentContext::RECURRING);

        // Shop B can see none of shop A's paperwork through the tenant-scoped model.
        Tenant::run($shopB, function (): void {
            $this->assertSame(0, IssuedDocument::query()->count());
        });

        Tenant::run($shopA, function (): void {
            $this->assertSame(1, IssuedDocument::query()->count());
        });
    }

    public function test_one_shops_ledger_can_never_be_invoiced_under_another_shops_id(): void
    {
        $shopA = $this->connectedShop('cross-a.myshopify.com');
        $shopB = $this->connectedShop('cross-b.myshopify.com');
        $this->fakeProvider();

        $ledgerA = $this->succeededLedger($shopA);

        // Shop B's id + shop A's ledger id: the lookup is scoped by BOTH, so it resolves
        // to nothing rather than billing A's money onto B's books.
        $result = (new DocumentIssuer())->issueForLedger(
            (int) $shopB->getKey(),
            (int) $ledgerA->getKey(),
            DocumentContext::RECURRING,
        );

        $this->assertNull($result);
        $this->assertCount(0, $this->issued);
    }

    // === Helpers ===

    /** Record every request and answer with a unique document id. */
    private function fakeProvider(bool $succeed = true): void
    {
        $test = $this;

        InvoiceProviderFactory::fake(fn (Shop $shop): InvoiceProvider => new class($test, $succeed) implements InvoiceProvider
        {
            public function __construct(private DocumentIssuerTest $test, private bool $succeed) {}

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

                return $this->succeed
                    ? IssuedDocumentResult::issued(
                        documentId: 'gi-'.count($this->test->issued),
                        documentNumber: (string) (60000 + count($this->test->issued)),
                        documentUrl: 'https://morning.example/d/'.count($this->test->issued),
                        documentType: '320',
                    )
                    : IssuedDocumentResult::failed('rejected', 'Provider is down.');
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

    private function succeededLedger(Shop $shop): PaymentLedger
    {
        return Tenant::run($shop, function () use ($shop): PaymentLedger {
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
                idempotencyKey: 'shop:'.$shop->getKey().':plan:'.$plan->getKey().':cycle:2026-07-22',
                amount: 100.0,
                currency: 'ILS',
                attributes: ['plan_id' => $plan->getKey()],
            );

            return Ledger::transition($ledger, LedgerStatus::SUCCEEDED);
        });
    }

    /**
     * @param  list<array<string, mixed>>|null  $lines
     * @return array<string, mixed>
     */
    private function orderPayload(float $total = 100.0, ?array $lines = null): array
    {
        return [
            'order_id' => '5501',
            'order_number' => '5501',
            'total' => $total,
            'currency' => 'ILS',
            'customer' => ['name' => 'Dana Buyer', 'email' => 'buyer@example.com', 'phone' => null, 'tax_id' => null],
            'lines' => $lines ?? [],
            'payment_gateway' => 'bacs',
            'card_last4' => null,
        ];
    }
}
