<?php

namespace Tests\Feature\Lifecycle;

use App\Domain\Billing\Ledger;
use App\Domain\Lifecycle\RefundService;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wave-1b refund (money OUT). Refunds a SUCCEEDED ledger row through PayPlus, then
 * transitions it succeeded → refunded + records a KIND_REFUNDED Timeline event. Guards:
 * already-refunded is a no-op, a non-succeeded row is rejected, and a gateway failure
 * leaves the row succeeded (the money truth is never falsely flipped).
 */
final class RefundServiceTest extends TestCase
{
    use RefreshDatabase;

    public int $refundCalls = 0;
    public bool $refundShouldFail = false;

    /** Runs INSIDE the gateway refund call — a second refund arriving mid-flight. */
    public ?\Closure $duringRefund = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refundCalls = 0;
        $this->refundShouldFail = false;
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private RefundServiceTest $test) {}

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                $this->test->refundCalls++;
                if ($this->test->duringRefund !== null) {
                    $sibling = $this->test->duringRefund;
                    $this->test->duringRefund = null;
                    $sibling();
                }
                if ($this->test->refundShouldFail) {
                    return GatewayResult::fromResponse(['results' => ['status' => 'error', 'code' => 5, 'description' => 'declined']]);
                }

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success'],
                    'data' => ['transaction' => ['uid' => 'refund-'.$this->test->refundCalls]],
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

    public function test_refund_processes_a_succeeded_charge(): void
    {
        $shop = $this->makeShop();
        $ledger = $this->makeLedger($shop, LedgerStatus::SUCCEEDED);
        Tenant::set($shop);

        $result = app(RefundService::class)->refund($ledger);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $this->refundCalls);
        $this->assertSame(LedgerStatus::REFUNDED->value, $ledger->fresh()->status);
        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $shop->getKey(),
            'kind' => Timeline::KIND_REFUNDED,
        ]);
    }

    public function test_already_refunded_is_a_noop(): void
    {
        $shop = $this->makeShop();
        $ledger = $this->makeLedger($shop, LedgerStatus::REFUNDED);
        Tenant::set($shop);

        $result = app(RefundService::class)->refund($ledger);

        $this->assertTrue($result['ok']);
        $this->assertSame('already_refunded', $result['message']);
        $this->assertSame(0, $this->refundCalls);
    }

    public function test_a_non_succeeded_row_is_not_refundable(): void
    {
        $shop = $this->makeShop();
        $ledger = $this->makeLedger($shop, LedgerStatus::PENDING);
        Tenant::set($shop);

        $result = app(RefundService::class)->refund($ledger);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_refundable', $result['message']);
        $this->assertSame(0, $this->refundCalls);
    }

    public function test_a_gateway_failure_leaves_the_row_succeeded(): void
    {
        $this->refundShouldFail = true;
        $shop = $this->makeShop();
        $ledger = $this->makeLedger($shop, LedgerStatus::SUCCEEDED);
        Tenant::set($shop);

        $result = app(RefundService::class)->refund($ledger);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $this->refundCalls);
        $this->assertSame(LedgerStatus::SUCCEEDED->value, $ledger->fresh()->status, 'a failed refund never flips the money truth');
    }

    /**
     * Two refunds racing on one charge: the second arrives while the first is
     * at PayPlus. The first's claim is on the row, so the second is refused
     * before the gateway — together they would have exceeded the charge.
     */
    public function test_a_concurrent_refund_is_refused_while_one_is_in_flight(): void
    {
        $shop = $this->makeShop();
        $ledger = $this->makeLedger($shop, LedgerStatus::SUCCEEDED); // 49.90
        Tenant::set($shop);

        $sibling = null;
        $this->duringRefund = function () use ($ledger, &$sibling): void {
            $sibling = app(RefundService::class)->refund($ledger->fresh(), 30.00);
        };

        $first = app(RefundService::class)->refund($ledger, 30.00);

        $this->assertTrue($first['ok']);
        $this->assertFalse($sibling['ok']);
        $this->assertSame('refund_in_flight', $sibling['message']);
        $this->assertSame(1, $this->refundCalls, 'The sibling never reached PayPlus.');

        $row = $ledger->fresh();
        $this->assertSame('30.00', (string) $row->refunded_amount);
        $this->assertSame('0.00', (string) $row->refunding_amount, 'The claim is settled into refunded_amount.');
    }

    public function test_a_failed_refund_releases_its_claim(): void
    {
        $this->refundShouldFail = true;
        $shop = $this->makeShop();
        $ledger = $this->makeLedger($shop, LedgerStatus::SUCCEEDED);
        Tenant::set($shop);

        app(RefundService::class)->refund($ledger, 10.00);

        $this->assertSame('0.00', (string) $ledger->fresh()->refunding_amount);

        // …so the merchant can try again straight away.
        $this->refundShouldFail = false;
        $this->assertTrue(app(RefundService::class)->refund($ledger->fresh(), 10.00)['ok']);
    }

    public function test_an_over_limit_amount_is_refused_under_the_lock(): void
    {
        $shop = $this->makeShop();
        $ledger = $this->makeLedger($shop, LedgerStatus::SUCCEEDED);
        Tenant::set($shop);

        $this->assertTrue(app(RefundService::class)->refund($ledger, 40.00)['ok']);

        // A stale model still says 0 refunded; the locked re-read does not.
        $result = app(RefundService::class)->refund($ledger, 20.00);

        $this->assertFalse($result['ok']);
        $this->assertSame('exceeds_remaining', $result['message']);
        $this->assertSame(1, $this->refundCalls);
    }

    // === Helpers ===

    private function makeShop(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'refund.myshopify.com',
            'name' => 'Refund',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return $shop;
    }

    private function makeLedger(Shop $shop, LedgerStatus $status): PaymentLedger
    {
        return Tenant::run($shop, function () use ($shop, $status): PaymentLedger {
            $ledger = Ledger::open((int) $shop->getKey(), 'recurring', 'key-'.Str::random(8), 49.90, 'ILS', [
                'payplus_transaction_uid' => 'txn-original',
            ]);

            if ($status === LedgerStatus::SUCCEEDED) {
                Ledger::transition($ledger, LedgerStatus::SUCCEEDED);
            } elseif ($status === LedgerStatus::REFUNDED) {
                Ledger::transition($ledger, LedgerStatus::SUCCEEDED);
                Ledger::transition($ledger, LedgerStatus::REFUNDED);
            }
            // PENDING: leave as opened.

            return $ledger->fresh();
        });
    }
}
