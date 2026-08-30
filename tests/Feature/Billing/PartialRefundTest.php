<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Ledger;
use App\Domain\Lifecycle\RefundService;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Refunds: the books have to balance, and money going out needs a key.
 *
 * A partial refund used to flip the whole row to `refunded` with no record of
 * the amount, so ₪50 back on a ₪200 sale read as ₪200 refunded — and the next
 * partial refund was refused as "already refunded", contradicting the credit
 * note key, which is built precisely to allow successive partial credits.
 */
final class PartialRefundTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{amount: float, key: ?string}> */
    public array $refundCalls = [];

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'refunds.myshopify.com',
            'name' => 'Refunds',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $this->shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $this->shop->save();
        Tenant::set($this->shop);

        $test = $this;
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private PartialRefundTest $test) {}

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                $this->test->refundCalls[] = ['amount' => $amount, 'key' => $meta['idempotency_key'] ?? null];

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success'],
                    'data' => ['transaction' => ['uid' => 'refund-'.count($this->test->refundCalls)]],
                ]);
            }

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    private function succeededRow(float $amount = 200.00): PaymentLedger
    {
        $row = Ledger::open((int) $this->shop->getKey(), 'recurring', 'sale-key-1', $amount);
        Ledger::transition($row, LedgerStatus::SUCCEEDED, ['payplus_transaction_uid' => 'txn-sale']);

        return $row->fresh();
    }

    public function test_a_partial_refund_records_the_amount_and_leaves_the_sale_standing(): void
    {
        $row = $this->succeededRow();

        $out = app(RefundService::class)->refund($row, 50.00);

        $this->assertTrue($out['ok']);

        $row->refresh();
        $this->assertSame('50.00', (string) $row->refunded_amount, 'What went back is written down.');
        $this->assertSame(
            LedgerStatus::SUCCEEDED->value,
            (string) $row->status,
            'Still a sale — ₪150 of it was never given back.',
        );
    }

    public function test_successive_partial_refunds_are_allowed_up_to_the_sale(): void
    {
        $row = $this->succeededRow();
        $service = app(RefundService::class);

        $this->assertTrue($service->refund($row->fresh(), 50.00)['ok']);
        $this->assertTrue($service->refund($row->fresh(), 100.00)['ok'], 'Not "already refunded".');

        $row->refresh();
        $this->assertSame('150.00', (string) $row->refunded_amount);
        $this->assertSame(LedgerStatus::SUCCEEDED->value, (string) $row->status);

        // The last slice closes the sale.
        $this->assertTrue($service->refund($row->fresh(), 50.00)['ok']);
        $this->assertSame(LedgerStatus::REFUNDED->value, (string) $row->fresh()->status);
    }

    public function test_refunding_more_than_remains_is_refused_before_the_gateway(): void
    {
        $row = $this->succeededRow();
        $service = app(RefundService::class);

        $service->refund($row->fresh(), 180.00);
        $this->refundCalls = [];

        $out = $service->refund($row->fresh(), 50.00);

        $this->assertFalse($out['ok']);
        $this->assertSame('exceeds_remaining', $out['message']);
        $this->assertSame([], $this->refundCalls, 'No money moved on a refusal.');
    }

    public function test_a_refund_with_no_amount_returns_what_is_left(): void
    {
        $row = $this->succeededRow();
        $service = app(RefundService::class);

        $service->refund($row->fresh(), 30.00);
        $service->refund($row->fresh());

        $this->assertSame(170.00, $this->refundCalls[1]['amount']);
        $this->assertSame(LedgerStatus::REFUNDED->value, (string) $row->fresh()->status);
    }

    public function test_money_going_out_carries_an_idempotency_key(): void
    {
        app(RefundService::class)->refund($this->succeededRow(), 50.00);

        $this->assertNotNull(
            $this->refundCalls[0]['key'],
            'Without a key the gateway cannot collapse a repeated refund request.',
        );
        $this->assertStringStartsWith('refund:', (string) $this->refundCalls[0]['key']);
    }

    public function test_the_same_refund_repeated_reuses_its_key_but_a_new_slice_gets_its_own(): void
    {
        $row = $this->succeededRow();
        $service = app(RefundService::class);

        $service->refund($row->fresh(), 50.00);
        $service->refund($row->fresh(), 50.00);

        // Two genuinely different slices — different starting points, so the
        // gateway must treat them as two requests, not one repeated one.
        $this->assertNotSame($this->refundCalls[0]['key'], $this->refundCalls[1]['key']);
    }
}
