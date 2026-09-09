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
use App\Domain\Refunds\StoreRefunderFactory;
use App\Models\MerchantInvoicingSettings;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * Orders this app never charged — a Shopify-Payments contract's cycle order, a
 * WooCommerce order paid with PayPal.
 *
 * On these the legs INVERT. There is no ledger row and no PayPlus transaction of
 * ours, so the store call is not a record of a refund that already happened: it
 * IS the refund. Which means two things must hold, and both are the opposite of
 * the PayPlus rail's rules:
 *
 *   - the call has to actually MOVE money (`api_refund: true`, a refund
 *     transaction naming its parent sale) — otherwise the shopper is never made
 *     whole and the merchant's order says otherwise;
 *   - a failure means NOTHING moved, so the request is `failed` and freely
 *     retryable, never the `needs_attention` that means "money is gone".
 */
final class DelegatedRailTest extends TestCase
{
    use MakesRefundScenarios;
    use RefreshDatabase;

    /** @var list<IssueDocumentRequest> */
    public array $issued = [];

    protected function tearDown(): void
    {
        InvoiceProviderFactory::clearFake();
        ShopifyClientFactory::clearFake();
        $this->clearRefundFakes();
        parent::tearDown();
    }

    // === WooCommerce: the order's own gateway ===

    public function test_a_woo_order_we_never_charged_asks_woocommerce_to_move_the_money(): void
    {
        Queue::fake();
        $shop = $this->wooShop();
        $this->fakeGateway();
        $this->fakeWooRest(total: '250.00');

        // No ledger rows at all: this order was paid with PayPal.
        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '8001']);

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(RefundRequest::RAIL_WOO_GATEWAY, $request->money_rail);
        $this->assertSame(250.00, $request->refundedTotal());
        $this->assertSame([], $this->gatewayRefunds, 'PayPlus never charged this order and must never be asked.');

        // POST only: the same path is GET-ed first to read what has already
        // been refunded, and that request has no body to read.
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/orders/8001/refunds')
            && $r['api_refund'] === true
            && $r['amount'] === '250.00');
    }

    public function test_the_ceiling_is_what_woocommerce_says_is_left(): void
    {
        Queue::fake();
        $shop = $this->wooShop();
        $this->fakeGateway();
        // A ₪250 order with ₪100 already refunded, as WooCommerce stores it:
        // refund amounts come back POSITIVE on the refunds endpoint.
        $this->fakeWooRest(total: '250.00', refunds: [['id' => 1, 'amount' => '100.00']]);

        $request = $this->refund($shop, [
            'mode' => RefundRequest::MODE_REFUND_PARTIAL,
            'order_id' => '8002',
            'amount' => 200.00,
        ]);

        $this->assertSame(RefundRequest::STATUS_FAILED, $request->status);
        $this->assertSame(RefundRequest::FAIL_NOTHING_TO_REFUND, $request->failure_code);
        $this->assertSame(150.00, (float) $request->money_result['refundable']);
    }

    public function test_a_store_refusal_here_means_nothing_moved(): void
    {
        Queue::fake();
        $shop = $this->wooShop();
        $this->fakeGateway();

        Http::fake([
            // The POST fails; the LIST (with its query string) still answers.
            '*/wp-json/wc/v3/orders/8003/refunds' => Http::response(['message' => 'gateway refused'], 500),
            '*/wp-json/wc/v3/orders/*/refunds*' => Http::response([], 200),
            '*/wp-json/wc/v3/orders/*' => Http::response(['id' => 8003, 'total' => '90.00', 'meta_data' => []], 200),
            '*' => Http::response([], 200),
        ]);

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '8003']);

        // NOT needs_attention: on this rail the store call was the refund, so a
        // failure means the shopper still has nothing and a retry is safe.
        $this->assertSame(RefundRequest::STATUS_FAILED, $request->status);
        $this->assertSame(RefundRequest::FAIL_MONEY, $request->failure_code);
        $this->assertSame(0.0, $request->refundedTotal());
    }

    public function test_the_credit_note_is_issued_against_the_order(): void
    {
        $shop = $this->wooShop();
        $this->fakeGateway();
        $this->fakeWooRest(total: '120.00');
        $this->fakeProvider();

        // The sale's own document, as the `all_orders` scope would have issued it.
        Tenant::run($shop, fn () => app(DocumentIssuer::class)->issueForPlatformOrder(
            (int) $shop->getKey(),
            [
                'order_id' => '8004',
                'order_number' => '8004',
                'total' => 120.00,
                'currency' => 'ILS',
                'customer' => ['name' => 'Dana Payer', 'email' => 'dana@example.com'],
                'payment_gateway' => 'paypal',
            ],
        ));

        $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '8004']);

        $credit = collect($this->issued)->first(
            static fn (IssueDocumentRequest $r): bool => $r->context === DocumentContext::REFUND,
        );

        $this->assertNotNull($credit, 'A refund on this rail still has to be declared.');
        $this->assertSame(120.00, round($credit->amount, 2));
        $this->assertNotNull($credit->linkedDocumentId, 'A credit note must name the sale it reverses.');
        $this->assertSame('Dana Payer', $credit->customer->name, 'Addressed to whoever the sale was.');
    }

    /**
     * No sale document means nothing to credit. Green Invoice rejects a credit
     * note with no linked document, so filing one anyway would be a dangling
     * declaration — the refund still stands and says so.
     */
    public function test_no_sale_document_means_no_dangling_credit_note(): void
    {
        $shop = $this->wooShop();
        $this->fakeGateway();
        $this->fakeWooRest(total: '75.00');
        $this->fakeProvider();

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '8005']);

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame([], $this->issued);
    }

    // === Shopify: its own gateway ===

    public function test_a_shopify_paid_order_refunds_through_its_parent_transaction(): void
    {
        Queue::fake();
        $shop = $this->shopifyShop();
        $this->fakeGateway();

        $client = new RecordingShopifyClient;
        $client->orderTransactions = [
            ['id' => 991, 'kind' => 'sale', 'status' => 'success', 'amount' => '300.00', 'gateway' => 'shopify_payments'],
        ];
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $client);

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '8101']);

        $this->assertSame(RefundRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(RefundRequest::RAIL_SHOPIFY_NATIVE, $request->money_rail);
        $this->assertSame([], $this->gatewayRefunds, 'PayPlus never charged this order.');

        $transaction = $this->refundInput($client)['transactions'][0];

        $this->assertSame('gid://shopify/OrderTransaction/991', $transaction['parentId']);
        $this->assertSame('shopify_payments', $transaction['gateway'], 'Filed under the gateway that took the money.');
        $this->assertSame('300.00', $transaction['amount']);
    }

    public function test_the_shopify_ceiling_is_captured_minus_already_refunded(): void
    {
        Queue::fake();
        $shop = $this->shopifyShop();
        $this->fakeGateway();

        $client = new RecordingShopifyClient;
        $client->orderTransactions = [
            ['id' => 1, 'kind' => 'sale', 'status' => 'success', 'amount' => '300.00', 'gateway' => 'shopify_payments'],
            ['id' => 2, 'kind' => 'refund', 'status' => 'success', 'amount' => '120.00', 'gateway' => 'shopify_payments'],
            // A failed attempt moved nothing and must not count.
            ['id' => 3, 'kind' => 'sale', 'status' => 'failure', 'amount' => '999.00', 'gateway' => 'shopify_payments'],
        ];
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $client);

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '8102']);

        $this->assertSame(180.00, $request->refundedTotal());
    }

    /**
     * A PayPlus-charged Shopify order must keep the manual, parent-less
     * transaction — this is the assertion that stands between a merchant and
     * refunding the same shopper twice.
     */
    public function test_our_own_rail_still_records_rather_than_moves(): void
    {
        Queue::fake();
        $shop = $this->shopifyShop();
        $this->fakeGateway();

        $client = new RecordingShopifyClient;
        $client->orderTransactions = [
            ['id' => 77, 'kind' => 'sale', 'status' => 'success', 'amount' => '50.00', 'gateway' => 'manual'],
        ];
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $client);

        // This one DOES have a ledger row: we charged it through PayPlus.
        $this->makeCharge($shop, orderId: '8103', amount: 50.00, uid: 'txn-sale');

        $request = $this->refund($shop, ['mode' => RefundRequest::MODE_REFUND_FULL, 'order_id' => '8103']);

        $this->assertSame(RefundRequest::RAIL_PAYPLUS, $request->money_rail);
        $this->assertSame(['txn-sale'], array_column($this->gatewayRefunds, 'uid'));
        $this->assertArrayNotHasKey('parentId', $this->refundInput($client)['transactions'][0]);
    }

    // === Helpers ===

    /** @return array<string, mixed> */
    private function refundInput(RecordingShopifyClient $client): array
    {
        foreach ($client->graphqlCalls as $call) {
            if (str_contains($call['query'], 'refundCreate(')) {
                return (array) $call['variables']['input'];
            }
        }

        $this->fail('refundCreate was never called.');
    }

    /** @param array<int, array<string, mixed>> $refunds */
    private function fakeWooRest(string $total, array $refunds = []): void
    {
        Http::fake([
            // Trailing `*`: the LIST call carries `?per_page=100`, and a pattern
            // ending at `refunds` matches only the POST — which is how "already
            // refunded" silently read as zero the first time.
            '*/wp-json/wc/v3/orders/*/refunds*' => static fn (Request $r) => $r->method() === 'POST'
                ? Http::response(['id' => 95001], 201)
                : Http::response($refunds, 200),
            '*/wp-json/wc/v3/orders/*/notes' => Http::response(['id' => 5], 201),
            '*/wp-json/wc/v3/orders/*' => Http::response(['id' => 8001, 'total' => $total, 'meta_data' => []], 200),
            '*' => Http::response([], 200),
        ]);
    }

    private function wooShop(): Shop
    {
        StoreRefunderFactory::clearFake();

        $shop = $this->makeShop(Shop::PLATFORM_WOOCOMMERCE);
        $shop->woocommerce_credentials = [
            'base_url' => 'https://refunds.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ];
        $shop->save();

        return $shop->fresh();
    }

    private function shopifyShop(): Shop
    {
        StoreRefunderFactory::clearFake();

        $shop = Shop::create([
            'shopify_domain' => 'delegated.myshopify.com',
            'name' => 'Delegated Co',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);

        $shop->payplus_credentials = [
            'api_key' => 'pk', 'secret_key' => 'sk',
            'terminal_uid' => 't', 'payment_page_uid' => 'pp',
        ];
        $shop->shopify_access_token = 'shpat_test';
        $shop->save();

        return $shop->fresh();
    }

    /** @param array<string, mixed> $input */
    private function refund(Shop $shop, array $input): RefundRequest
    {
        $orchestrator = app(RefundOrchestrator::class);

        return $orchestrator->run($shop, $orchestrator->open($shop, $input));
    }

    private function fakeProvider(): void
    {
        $test = $this;

        Tenant::run($this->currentShop(), static function (): void {
            MerchantInvoicingSettings::current()->forceFill(['enabled' => true])->save();
        });

        InvoiceProviderFactory::fake(fn (Shop $shop): InvoiceProvider => new class($test) implements InvoiceProvider
        {
            public function __construct(private DelegatedRailTest $test) {}

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
                    documentNumber: (string) (80000 + count($this->test->issued)),
                    documentUrl: 'https://morning.example/d/'.count($this->test->issued),
                    documentType: '330',
                );
            }
        });
    }

    /** The shop built most recently — the invoicing switch belongs to it. */
    private function currentShop(): Shop
    {
        return Shop::query()->latest('id')->firstOrFail();
    }
}
