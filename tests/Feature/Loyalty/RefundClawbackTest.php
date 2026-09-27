<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Billing\Ledger;
use App\Domain\Lifecycle\RefundService;
use App\Domain\Loyalty\PointsEngine;
use App\Models\LoyaltyPointEvent;
use App\Models\MerchantLoyaltySettings;
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
 * A refund takes back the points its money earned. Buy → earn → redeem →
 * refund used to be a free-credit loop; every refund slice now writes a
 * `refund_clawback` event for its share of the earning (and of a referrer's
 * grant on the same order), keyed so a replay takes once, floored at the
 * balance so spent points never become a debt.
 */
final class RefundClawbackTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CUSTOMER = 'cust-9';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'clawback.myshopify.com',
            'name' => 'Clawback',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $this->shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $this->shop->save();
        Tenant::set($this->shop);

        MerchantLoyaltySettings::current()->forceFill([
            'enabled' => true,
            'points_per_currency' => 1,
            'join_bonus_points' => 0,
        ])->save();

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class implements PayPlusGatewayInterface
        {
            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success'], 'data' => ['transaction' => ['uid' => 'r-1']]]);
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

    public function test_a_partial_refund_takes_back_its_share_of_the_points(): void
    {
        $member = app(PointsEngine::class)->join(self::CUSTOMER, 'buyer@example.com');
        $ledger = $this->paidCharge(200.00); // earns 200 points via the ledger listener

        $this->assertSame(200, (int) $member->refresh()->points_balance);

        $this->assertTrue(app(RefundService::class)->refund($ledger, 50.00)['ok']);

        $this->assertSame(150, (int) $member->refresh()->points_balance, 'A quarter refunded, a quarter taken back.');
        $this->assertSame(150.0, (float) $member->lifetime_spend);
        $this->assertSame(1, LoyaltyPointEvent::query()->where('kind', LoyaltyPointEvent::KIND_REFUND_CLAWBACK)->count());

        // The rest goes back later: the remaining points follow, never more.
        $this->assertTrue(app(RefundService::class)->refund($ledger->fresh())['ok']);
        $this->assertSame(0, (int) $member->refresh()->points_balance);
    }

    public function test_spent_points_are_not_turned_into_a_debt(): void
    {
        $member = app(PointsEngine::class)->join(self::CUSTOMER, 'buyer@example.com');
        $ledger = $this->paidCharge(200.00);

        // Most of it already redeemed.
        app(PointsEngine::class)->deduct($member, 180, 'redeem:test');

        $this->assertTrue(app(RefundService::class)->refund($ledger)['ok']);

        $this->assertSame(0, (int) $member->refresh()->points_balance, 'Floored at zero, never negative.');
    }

    public function test_a_replayed_refund_event_takes_once(): void
    {
        $member = app(PointsEngine::class)->join(self::CUSTOMER, 'buyer@example.com');
        $this->paidCharge(100.00);
        $earning = LoyaltyPointEvent::query()->where('kind', LoyaltyPointEvent::KIND_EARN_PURCHASE)->firstOrFail();

        app(PointsEngine::class)->clawbackForRefund($earning, 0.5, 50.0, 'refund-ref-1');
        app(PointsEngine::class)->clawbackForRefund($earning, 0.5, 50.0, 'refund-ref-1');

        $this->assertSame(50, (int) $member->refresh()->points_balance);
    }

    /** A SUCCEEDED charge for the member — accrual rides LedgerRowSucceeded. */
    private function paidCharge(float $amount): PaymentLedger
    {
        $ledger = Ledger::open((int) $this->shop->getKey(), 'recurring', 'key-'.uniqid(), $amount, 'ILS', [
            'payplus_transaction_uid' => 'txn-'.uniqid(),
            'shopify_customer_id' => self::CUSTOMER,
        ]);
        Ledger::transition($ledger, LedgerStatus::SUCCEEDED);

        return $ledger->fresh();
    }
}
