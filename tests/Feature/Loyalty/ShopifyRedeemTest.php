<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Loyalty\PointsEngine;
use App\Domain\Loyalty\RedeemService;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * Redemption on the Shopify rail — the path that moves real money onto a
 * customer's store-credit account. The same one rule as the Woo tests: the
 * platform moves the money FIRST, and points leave only after it has. Plus the
 * Shopify-specific refusals: a userErrors answer (the missing-scope shape) and
 * a member whose ref cannot become a customer GID.
 */
final class ShopifyRedeemTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private RecordingShopifyClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'redeem.myshopify.com',
            'name' => 'Redeem Shopify',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        $this->shop->forceFill(['shopify_access_token' => 'shpat_test'])->save();
        Tenant::set($this->shop->fresh());

        MerchantLoyaltySettings::current()->forceFill([
            'enabled' => true,
            'redeem_rate_points' => 100,
            'redeem_rate_amount' => 10.00,
            'min_redeem_points' => 0,
            'join_bonus_points' => 0,
        ])->save();

        $this->client = new RecordingShopifyClient;
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $this->client);
    }

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_successful_credit_deducts_the_points_and_returns_no_code(): void
    {
        $account = $this->memberWith(250);
        $this->client->graphqlResponses[] = [
            'data' => ['storeCreditAccountCredit' => [
                'storeCreditAccountTransaction' => ['amount' => ['amount' => '20.00', 'currencyCode' => 'ILS']],
                'userErrors' => [],
            ]],
        ];

        $result = app(RedeemService::class)->redeem($this->shop->fresh(), $account, 'ILS');

        $this->assertTrue($result['ok']);
        $this->assertSame(20.0, $result['amount']);
        // Nothing to copy on Shopify — the balance simply appears on the account.
        $this->assertNull($result['code']);
        $this->assertSame(50, (int) $account->refresh()->points_balance);

        // The mutation went to the member's customer GID with the exact amount.
        $call = $this->client->graphqlCalls[0];
        $this->assertSame('gid://shopify/Customer/42', $call['variables']['id']);
        $this->assertSame('20.00', $call['variables']['creditInput']['creditAmount']['amount']);
        $this->assertSame('ILS', $call['variables']['creditInput']['creditAmount']['currencyCode']);
    }

    public function test_a_user_errors_answer_costs_the_customer_nothing(): void
    {
        // The missing-scope shape: HTTP 200, userErrors inside.
        $account = $this->memberWith(300);
        $this->client->graphqlResponses[] = [
            'data' => ['storeCreditAccountCredit' => [
                'storeCreditAccountTransaction' => null,
                'userErrors' => [['field' => null, 'message' => 'Access denied for storeCreditAccountCredit']],
            ]],
        ];

        $result = app(RedeemService::class)->redeem($this->shop->fresh(), $account, 'ILS');

        $this->assertFalse($result['ok']);
        $this->assertSame(RedeemService::ERR_FAILED, $result['reason']);
        $this->assertSame(300, (int) $account->refresh()->points_balance, 'A refused credit must cost the customer nothing.');
        $this->assertSame(0, LoyaltyPointEvent::query()->where('kind', LoyaltyPointEvent::KIND_REDEEM)->count());
    }

    public function test_a_transport_failure_costs_the_customer_nothing(): void
    {
        $account = $this->memberWith(300);
        $this->client->graphqlThrows = new \RuntimeException('boom');

        $result = app(RedeemService::class)->redeem($this->shop->fresh(), $account, 'ILS');

        $this->assertFalse($result['ok']);
        $this->assertSame(RedeemService::ERR_FAILED, $result['reason']);
        $this->assertSame(300, (int) $account->refresh()->points_balance);
    }

    public function test_a_member_without_a_numeric_ref_is_refused_before_any_call(): void
    {
        // An email-keyed member (imported, say) has no customer GID to credit.
        $account = app(PointsEngine::class)->join('not-a-customer-id', 'lea@example.com');
        $account->forceFill(['points_balance' => 200, 'lifetime_points' => 200])->save();

        $result = app(RedeemService::class)->redeem($this->shop->fresh(), $account->refresh(), 'ILS');

        $this->assertFalse($result['ok']);
        $this->assertSame(RedeemService::ERR_FAILED, $result['reason']);
        $this->assertSame([], $this->client->graphqlCalls, 'No mutation may fire without a customer GID.');
        $this->assertSame(200, (int) $account->refresh()->points_balance);
    }

    public function test_a_disconnected_shop_is_unavailable(): void
    {
        $this->shop->forceFill(['shopify_access_token' => null])->save();
        $account = $this->memberWith(200);

        $result = app(RedeemService::class)->redeem($this->shop->fresh(), $account, 'ILS');

        $this->assertFalse($result['ok']);
        $this->assertSame(RedeemService::ERR_UNAVAILABLE, $result['reason']);
        $this->assertSame(200, (int) $account->refresh()->points_balance);
    }

    // === Helpers ===

    private function memberWith(int $points): LoyaltyAccount
    {
        $account = app(PointsEngine::class)->join('42', 'dana@example.com', 'Dana');
        $account->forceFill(['points_balance' => $points, 'lifetime_points' => $points])->save();

        return $account->refresh();
    }
}
