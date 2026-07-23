<?php

namespace Tests\Feature\ShopifySubscriptions;

use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * The personal area's OWNERSHIP wall. Contract GIDs are guessable strings
 * (sequential integers under a public prefix), so "authenticated to SOME shop"
 * must never mean "may act on ANY contract". The controller matches the session
 * token's `sub` (the logged-in customer) against the mirrored contract's owner
 * before any verb runs — these tests prove a stolen GID is worthless.
 */
final class CustomerContractOwnershipTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SECRET = 'shpss_test_secret';
    private const API_KEY = 'test_api_key';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('shopify.api_secret', self::SECRET);
        Config::set('shopify.api_key', self::API_KEY);
    }

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_customer_can_pause_their_own_contract(): void
    {
        [$shop, $contract] = $this->shopWithContract(ownerCustomerId: 77);
        $this->fakePauseSuccess($contract);

        $response = $this->postJson('/subscriptions/api/pause', [
            'contract_gid' => (string) $contract->shopify_gid,
        ], ['Authorization' => 'Bearer '.$this->token($shop, sub: '77')]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('PAUSED', $contract->fresh()->status);
    }

    public function test_a_different_customer_cannot_act_on_someone_elses_contract(): void
    {
        [$shop, $contract] = $this->shopWithContract(ownerCustomerId: 77);
        $this->fakePauseSuccess($contract); // must never be reached

        // Customer 88, same shop, valid token — a STOLEN contract GID.
        $response = $this->postJson('/subscriptions/api/pause', [
            'contract_gid' => (string) $contract->shopify_gid,
        ], ['Authorization' => 'Bearer '.$this->token($shop, sub: '88')]);

        $response->assertNotFound()->assertJson(['ok' => false, 'reason' => 'not_yours']);
        $this->assertSame('ACTIVE', $contract->fresh()->status, 'The contract must be untouched.');
    }

    public function test_a_token_without_a_customer_identity_gets_no_verbs(): void
    {
        [$shop, $contract] = $this->shopWithContract(ownerCustomerId: 77);
        $this->fakePauseSuccess($contract); // must never be reached

        // A valid ADMIN-surface token: authenticated, but `sub` is not a customer.
        $response = $this->postJson('/subscriptions/api/pause', [
            'contract_gid' => (string) $contract->shopify_gid,
        ], ['Authorization' => 'Bearer '.$this->token($shop, sub: 'not-a-customer-id')]);

        $response->assertForbidden()->assertJson(['reason' => 'no_customer']);
    }

    public function test_no_token_means_no_entry_at_all(): void
    {
        [$shop, $contract] = $this->shopWithContract(ownerCustomerId: 77);

        $this->postJson('/subscriptions/api/pause', [
            'contract_gid' => (string) $contract->shopify_gid,
        ])->assertUnauthorized();
    }

    public function test_a_past_reschedule_date_is_refused_before_any_shopify_call(): void
    {
        [$shop, $contract] = $this->shopWithContract(ownerCustomerId: 77);
        $recorder = $this->fakePauseSuccess($contract);

        $this->postJson('/subscriptions/api/reschedule', [
            'contract_gid' => (string) $contract->shopify_gid,
            'date' => now()->subDay()->toDateString(),
        ], ['Authorization' => 'Bearer '.$this->token($shop, sub: '77')])
            ->assertUnprocessable();

        $this->assertCount(0, $recorder->graphqlCalls, 'A past date must never reach Shopify.');
    }

    // === Helpers ===

    /** @return array{0:Shop,1:SubscriptionContract} */
    private function shopWithContract(int $ownerCustomerId): array
    {
        $shop = Shop::create([
            'shopify_domain' => 'owner-wall.myshopify.com',
            'name' => 'Owner Wall',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->forceFill(['shopify_access_token' => 'tok'])->save();

        $contract = new SubscriptionContract();
        $contract->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'shopify_gid' => 'gid://shopify/SubscriptionContract/5001',
            'shopify_customer_gid' => 'gid://shopify/Customer/'.$ownerCustomerId,
            'status' => SubscriptionContract::STATUS_ACTIVE,
            'interval' => 'MONTH',
            'interval_count' => 1,
            'next_billing_date' => now()->addMonth(),
            'currency' => 'USD',
        ])->save();

        return [$shop->fresh(), $contract];
    }

    private function fakePauseSuccess(SubscriptionContract $contract): RecordingShopifyClient
    {
        $recorder = new RecordingShopifyClient();
        $recorder->graphqlResponses = [
            ['data' => ['subscriptionContractPause' => [
                'contract' => [
                    'id' => (string) $contract->shopify_gid,
                    'status' => 'PAUSED',
                    'nextBillingDate' => now()->addMonth()->toIso8601String(),
                    'currencyCode' => 'USD',
                    'billingPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1],
                ],
                'userErrors' => [],
            ]]],
        ];
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        return $recorder;
    }

    /**
     * A REAL HS256 session token, signed with the configured secret — the same
     * shape App Bridge mints, so SessionTokenAuth verifies it for real (no
     * middleware mocking; a broken verifier fails these tests, as it should).
     */
    private function token(Shop $shop, string $sub): string
    {
        $now = time();
        $claims = [
            'iss' => 'https://'.$shop->shopify_domain.'/admin',
            'dest' => 'https://'.$shop->shopify_domain,
            'aud' => self::API_KEY,
            'sub' => $sub,
            'exp' => $now + 60,
            'nbf' => $now - 5,
            'iat' => $now,
            'jti' => uniqid(),
            'sid' => uniqid(),
        ];

        $encode = static fn (array $part): string => rtrim(strtr(
            base64_encode((string) json_encode($part)),
            '+/',
            '-_',
        ), '=');

        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode($claims);
        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $header.'.'.$payload, self::SECRET, true),
        ), '+/', '-_'), '=');

        return $header.'.'.$payload.'.'.$signature;
    }
}
