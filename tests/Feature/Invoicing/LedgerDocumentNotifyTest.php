<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentIssuer;
use App\Domain\Invoicing\InvoiceProviderFactory;
use App\Domain\Invoicing\IssueDocumentRequest;
use App\Domain\Invoicing\IssuedDocumentResult;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Models\InstallmentPlan;
use App\Models\MerchantInvoicingSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The notify RETURN LEG for LEDGER-path documents.
 *
 * Until now only `all_orders` platform documents told the WooCommerce store their
 * number + URL; a deposit / recurring-cycle document — issued through the ledger
 * pipeline — never did, so the plugin's order screen showed plan orders as
 * undocumented forever. The job now routes the ledger branch through the same
 * notify leg, guarded on the one thing that makes a notification meaningful: a
 * document that NAMES its order (external_order_id).
 */
final class LedgerDocumentNotifyTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const NOTIFY_PATH = '/wp-json/lets-payplus/v1/notify';

    protected function tearDown(): void
    {
        InvoiceProviderFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_ledger_document_with_an_order_notifies_the_store(): void
    {
        Http::fake(['*'.self::NOTIFY_PATH => Http::response(['ok' => true], 200)]);
        $shop = $this->wooShop('notify-yes.example.com');
        $this->fakeProvider();

        $ledger = $this->succeededLedger($shop, wcOrderId: '7100');

        (new IssueDocumentJob(
            shopId: (int) $shop->getKey(),
            context: DocumentContext::RECURRING->value,
            ledgerId: (int) $ledger->getKey(),
        ))->handle(new DocumentIssuer());

        Http::assertSent(function (HttpRequest $req): bool {
            if (! str_ends_with($req->url(), self::NOTIFY_PATH)) {
                return false;
            }
            $body = $req->data();

            return ($body['event'] ?? null) === 'document_issued'
                && ($body['order_id'] ?? null) === '7100'
                && ($body['document_number'] ?? '') !== '';
        });
    }

    public function test_a_ledger_document_without_an_order_notifies_nothing(): void
    {
        Http::fake();
        $shop = $this->wooShop('notify-no.example.com');
        $this->fakeProvider();

        // No shopify_order_id on the ledger → the document has no external_order_id
        // → nothing the store could attach it to.
        $ledger = $this->succeededLedger($shop, wcOrderId: null);

        (new IssueDocumentJob(
            shopId: (int) $shop->getKey(),
            context: DocumentContext::RECURRING->value,
            ledgerId: (int) $ledger->getKey(),
        ))->handle(new DocumentIssuer());

        Http::assertNothingSent();
    }

    public function test_attach_to_order_off_suppresses_the_notification(): void
    {
        Http::fake();
        $shop = $this->wooShop('notify-off.example.com');
        MerchantInvoicingSettings::forShop((int) $shop->getKey())
            ->forceFill(['attach_to_order' => false])->save();
        $this->fakeProvider();

        $ledger = $this->succeededLedger($shop, wcOrderId: '7200');

        (new IssueDocumentJob(
            shopId: (int) $shop->getKey(),
            context: DocumentContext::RECURRING->value,
            ledgerId: (int) $ledger->getKey(),
        ))->handle(new DocumentIssuer());

        Http::assertNothingSent();
    }

    // === Fixtures ===

    private function fakeProvider(): void
    {
        InvoiceProviderFactory::fake(fn (Shop $shop): InvoiceProvider => new class implements InvoiceProvider
        {
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
                return IssuedDocumentResult::issued(
                    documentId: 'gi-1',
                    documentNumber: '61000',
                    documentUrl: 'https://morning.example/d/1',
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
