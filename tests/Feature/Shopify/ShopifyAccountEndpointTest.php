<?php

namespace Tests\Feature\Shopify;

use App\Domain\Account\CustomerSubscriptionActions;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The Shopify personal-area API (/subscriptions/api/account*), consumed by the
 * customer-account extension with a session-token bearer.
 *
 * The wall under test: identity is EXACTLY the verified token's customer id —
 * never anything the request body claims. The nastiest case is the email one:
 * AccountVisitor treats an email as a second identity, so a caller who could
 * smuggle one in would read (and cancel) plans keyed only to someone else's
 * email. These tests prove the body's email is dead on arrival.
 */
final class ShopifyAccountEndpointTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SECRET = 'shpss_test_secret';
    private const API_KEY = 'test_api_key';

    private const BOOTSTRAP = '/subscriptions/api/account';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('shopify.api_secret', self::SECRET);
        Config::set('shopify.api_key', self::API_KEY);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_bootstrap_returns_only_the_token_customers_plans(): void
    {
        $shop = $this->shop();
        $mine = $this->plan($shop, shopifyCustomerId: '77', email: 'dana@example.com');
        $this->plan($shop, shopifyCustomerId: '88', email: 'yossi@example.com');

        $this->getJson(self::BOOTSTRAP, ['Authorization' => 'Bearer '.$this->token($shop, sub: '77')])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('account.identified', true)
            ->assertJsonCount(1, 'account.subscriptions')
            ->assertJsonPath('account.subscriptions.0.id', (string) $mine->public_id)
            ->assertJsonStructure(['account' => ['sections', 'appearance', 'copy']]);
    }

    public function test_an_email_in_the_request_is_ignored(): void
    {
        $shop = $this->shop();
        // A plan keyed ONLY by email — no customer id at all (an imported member).
        $this->plan($shop, shopifyCustomerId: null, email: 'victim@example.com');

        // Customer 77, authenticated, tries to claim the victim's email in every
        // channel a request has.
        $this->getJson(
            self::BOOTSTRAP.'?email=victim@example.com',
            ['Authorization' => 'Bearer '.$this->token($shop, sub: '77')],
        )
            ->assertOk()
            ->assertJsonCount(0, 'account.subscriptions');

        $this->postJson(
            self::BOOTSTRAP.'/pause',
            ['email' => 'victim@example.com', 'subscription' => 'whatever'],
            ['Authorization' => 'Bearer '.$this->token($shop, sub: '77')],
        )->assertNotFound();
    }

    public function test_a_token_without_a_customer_identity_is_refused(): void
    {
        $shop = $this->shop();

        // A valid ADMIN-surface token: authenticated, but `sub` is not a customer.
        $this->getJson(self::BOOTSTRAP, ['Authorization' => 'Bearer '.$this->token($shop, sub: 'not-a-customer')])
            ->assertForbidden()
            ->assertJsonPath('reason', 'no_customer');
    }

    public function test_no_token_means_no_entry(): void
    {
        $this->shop();

        $this->getJson(self::BOOTSTRAP)->assertUnauthorized();
    }

    public function test_a_customer_can_pause_their_own_plan(): void
    {
        $shop = $this->shop();
        $plan = $this->plan($shop, shopifyCustomerId: '77', email: 'dana@example.com');

        $this->postJson(
            self::BOOTSTRAP.'/pause',
            ['subscription' => (string) $plan->public_id],
            ['Authorization' => 'Bearer '.$this->token($shop, sub: '77')],
        )
            ->assertOk()
            ->assertJsonPath('result', CustomerSubscriptionActions::RESULT_OK)
            // The refreshed model rides back so the page redraws from the truth.
            ->assertJsonPath('account.subscriptions.0.status', 'paused');

        $this->assertSame(PlanStatus::PAUSED, Tenant::run($shop, fn () => $plan->refresh()->status));
    }

    public function test_someone_elses_plan_answers_404_not_403(): void
    {
        $shop = $this->shop();
        $theirs = $this->plan($shop, shopifyCustomerId: '88', email: 'yossi@example.com');

        $this->postJson(
            self::BOOTSTRAP.'/pause',
            ['subscription' => (string) $theirs->public_id],
            ['Authorization' => 'Bearer '.$this->token($shop, sub: '77')],
        )->assertNotFound();

        $this->assertSame(PlanStatus::ACTIVE, Tenant::run($shop, fn () => $theirs->refresh()->status));
    }

    public function test_an_unknown_action_is_not_routed(): void
    {
        $shop = $this->shop();

        $this->postJson(
            self::BOOTSTRAP.'/delete',
            ['subscription' => 'whatever'],
            ['Authorization' => 'Bearer '.$this->token($shop, sub: '77')],
        )->assertNotFound();
    }

    public function test_the_gid_form_of_the_sub_claim_works_too(): void
    {
        $shop = $this->shop();
        $mine = $this->plan($shop, shopifyCustomerId: '77', email: 'dana@example.com');

        $this->getJson(
            self::BOOTSTRAP,
            ['Authorization' => 'Bearer '.$this->token($shop, sub: 'gid://shopify/Customer/77')],
        )
            ->assertOk()
            ->assertJsonCount(1, 'account.subscriptions')
            ->assertJsonPath('account.subscriptions.0.id', (string) $mine->public_id);
    }

    // === Fixtures ===

    private function shop(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'account-api.myshopify.com',
            'name' => 'Account API',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        $shop->forceFill(['shopify_access_token' => 'tok'])->save();

        return $shop->fresh();
    }

    private function plan(Shop $shop, ?string $shopifyCustomerId, string $email): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $shopifyCustomerId, $email): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->forceFill([
                'shop_id' => $shop->getKey(),
                'public_id' => 'PLN-'.uniqid(),
                'shopify_customer_id' => $shopifyCustomerId,
                'customer_email' => $email,
                'plan_kind' => PlanKind::RECURRING->value,
                'status' => PlanStatus::ACTIVE->value,
                'total_amount' => 0,
                'total_charged' => 0,
                'installment_amount' => 89,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'next_charge_at' => now()->addDays(10)->startOfDay(),
            ])->save();

            return $plan;
        });
    }

    /** A REAL HS256 session token — the same shape App Bridge mints. */
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
