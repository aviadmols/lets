<?php

namespace Tests\Feature\Account;

use App\Domain\Account\CustomerSubscriptionActions;
use App\Models\CustomerLoginCode;
use App\Models\InstallmentPlan;
use App\Models\MerchantPortalAppearance;
use App\Models\MerchantSmsSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Sms\Sms019Sender;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The plugin-facing HTTP surface of the personal area.
 *
 * The auth model is the same one every WooCommerce endpoint uses — the plugin's
 * SERVER signs with the shared secret — so the tests worth having are about what
 * the endpoints refuse: an unsigned caller, another shopper's plan, and any
 * attempt to learn whether an address has an account.
 */
final class AccountEndpointTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const BOOTSTRAP = '/api/woocommerce/account/bootstrap';

    private const OTP_REQUEST = '/api/woocommerce/account/otp/request';

    private const OTP_VERIFY = '/api/woocommerce/account/otp/verify';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_every_account_endpoint_rejects_an_unsigned_call(): void
    {
        foreach ([self::BOOTSTRAP, self::OTP_REQUEST, self::OTP_VERIFY] as $path) {
            $this->postJson($path, [])->assertStatus(401);
        }

        $this->postJson('/api/woocommerce/account/subscriptions/pause', [])->assertStatus(401);
    }

    public function test_an_unknown_action_is_not_routed(): void
    {
        [, $key, $secret] = $this->connectedShop('acct-route.example.com');

        // The route whitelists the six verbs; anything else must 404 rather than
        // reach a controller that would have to decide what to do with it.
        $this->signedPost($key, $secret, '/api/woocommerce/account/subscriptions/delete', [])
            ->assertStatus(404);
    }

    public function test_bootstrap_returns_the_shell_for_a_logged_out_visitor(): void
    {
        [, $key, $secret] = $this->connectedShop('acct-guest.example.com');

        $this->signedPost($key, $secret, self::BOOTSTRAP, ['customer_ref' => ''])
            ->assertOk()
            ->assertJsonPath('account.identified', false)
            ->assertJsonPath('account.subscriptions', [])
            // The shell is still there: the plugin needs the copy and the login
            // settings to draw a sign-in prompt rather than a blank page.
            ->assertJsonStructure(['account' => ['sections', 'appearance', 'copy', 'login']])
            // Google rides the shell too — the button renders for a logged-OUT
            // visitor, and null (not a missing key) is how "not configured" reads.
            ->assertJsonPath('account.login.google_client_id', null);
    }

    public function test_a_configured_google_client_id_reaches_the_shell(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('acct-google.example.com');

        Tenant::run($shop, static function (): void {
            $settings = MerchantPortalAppearance::current();
            $settings->login_google_client_id = '1234567890-abc123.apps.googleusercontent.com';
            $settings->save();
        });

        $this->signedPost($key, $secret, self::BOOTSTRAP, ['customer_ref' => ''])
            ->assertOk()
            ->assertJsonPath('account.login.google_client_id', '1234567890-abc123.apps.googleusercontent.com');
    }

    public function test_bootstrap_returns_only_the_callers_own_subscriptions(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('acct-mine.example.com');

        $mine = $this->plan($shop, '7', 'dana@example.com');
        $this->plan($shop, '8', 'yossi@example.com');

        $this->signedPost($key, $secret, self::BOOTSTRAP, [
            'customer_ref' => '7',
            'email' => 'dana@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('account.identified', true)
            ->assertJsonCount(1, 'account.subscriptions')
            ->assertJsonPath('account.subscriptions.0.id', (string) $mine->public_id);
    }

    public function test_an_action_on_another_shoppers_plan_is_a_404(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('acct-theirs.example.com');

        $theirs = $this->plan($shop, '8', 'yossi@example.com');

        // 404, not 403: a Forbidden would confirm the subscription exists.
        $this->signedPost($key, $secret, '/api/woocommerce/account/subscriptions/pause', [
            'customer_ref' => '7',
            'email' => 'dana@example.com',
            'subscription' => (string) $theirs->public_id,
        ])->assertStatus(404);

        $this->assertSame(PlanStatus::ACTIVE, Tenant::run($shop, fn () => $theirs->refresh()->status));
    }

    public function test_an_action_returns_the_refreshed_area(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('acct-act.example.com');

        $plan = $this->plan($shop, '7', 'dana@example.com');

        $this->signedPost($key, $secret, '/api/woocommerce/account/subscriptions/pause', [
            'customer_ref' => '7',
            'email' => 'dana@example.com',
            'subscription' => (string) $plan->public_id,
        ])
            ->assertOk()
            ->assertJsonPath('result', CustomerSubscriptionActions::RESULT_OK)
            // The whole model comes back so the page redraws from the truth: an
            // action can move a date, which moves the benefit timeline with it.
            ->assertJsonPath('account.subscriptions.0.status', 'paused');
    }

    public function test_the_code_request_answers_the_same_for_a_stranger_and_a_customer(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('acct-otp.example.com');
        $this->enableCodeLogin($shop);

        $known = $this->signedPost($key, $secret, self::OTP_REQUEST, [
            'channel' => 'email', 'destination' => 'dana@example.com',
        ]);
        $stranger = $this->signedPost($key, $secret, self::OTP_REQUEST, [
            'channel' => 'email', 'destination' => 'nobody@example.com',
        ]);

        // Byte-identical: anything else is an account-enumeration oracle, and the
        // merchant's customer list is not ours to leak.
        $this->assertSame($known->json(), $stranger->json());
        $this->assertTrue($known->json('ok'));
    }

    public function test_a_wrong_code_never_verifies(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('acct-otp2.example.com');
        $this->enableCodeLogin($shop);

        $this->signedPost($key, $secret, self::OTP_REQUEST, [
            'channel' => 'email', 'destination' => 'dana@example.com',
        ]);

        $this->signedPost($key, $secret, self::OTP_VERIFY, [
            'channel' => 'email', 'destination' => 'dana@example.com', 'code' => '000000',
        ])
            ->assertOk()
            ->assertJsonPath('verified', false);
    }

    public function test_verify_issues_no_session_or_token_of_its_own(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('acct-otp3.example.com');
        $this->enableCodeLogin($shop);

        $response = $this->signedPost($key, $secret, self::OTP_VERIFY, [
            'channel' => 'email', 'destination' => 'dana@example.com', 'code' => '123456',
        ])->assertOk();

        // The SaaS attests; WordPress logs in. If a token ever appears in this
        // response, a leaked shared secret becomes a login.
        $body = $response->json();
        $this->assertSame(['ok', 'verified', 'reason'], array_keys($body));
    }

    public function test_an_sms_code_survives_the_shopper_retyping_the_number(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('acct-otp-sms.example.com');
        $this->enableCodeLogin($shop);
        $this->enableSms($shop);

        Http::fake([Sms019Sender::ENDPOINT => Http::response(['status' => 0], 200)]);

        $this->signedPost($key, $secret, self::OTP_REQUEST, [
            'channel' => 'sms', 'destination' => '050-123 4567',
        ])->assertOk()->assertJsonPath('offered', true);

        $code = null;
        Http::assertSent(static function (Request $request) use (&$code): bool {
            preg_match('/\d{6}/', (string) $request->data()['sms']['message'], $found);
            $code = $found[0] ?? null;

            return true;
        });
        $this->assertNotNull($code, 'the 019 message should carry the code');

        // The message arrived on the handset; the shopper comes back and types the
        // number the plain way. Same phone, so it must be the same code.
        $this->signedPost($key, $secret, self::OTP_VERIFY, [
            'channel' => 'sms', 'destination' => '+972501234567', 'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('verified', true);
    }

    public function test_a_shop_with_code_login_off_offers_nothing(): void
    {
        [, $key, $secret] = $this->connectedShop('acct-otp4.example.com');

        $this->signedPost($key, $secret, self::OTP_REQUEST, [
            'channel' => 'email', 'destination' => 'dana@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('offered', false);

        $this->assertSame(0, CustomerLoginCode::query()->withoutGlobalScopes()->count());
    }

    // === Fixtures ===

    /** @return array{0: Shop, 1: string, 2: string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];

        $json = (string) base64_decode(strtr($result['connection_token'], '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [$shop->fresh(), (string) $data['k'], (string) $data['s']];
    }

    private function enableCodeLogin(Shop $shop): void
    {
        Tenant::run($shop, static function (): void {
            $settings = MerchantPortalAppearance::current();
            $settings->login_code_enabled = true;
            $settings->login_code_channel = MerchantPortalAppearance::CHANNEL_BOTH;
            $settings->save();
        });
    }

    private function enableSms(Shop $shop): void
    {
        Tenant::run($shop, static function (): void {
            MerchantSmsSettings::current()->forceFill([
                'enabled' => true,
                'username' => 'shop-user',
                'api_token' => 'secret-token',
                'sender' => 'LETS',
            ])->save();
        });
    }

    private function plan(Shop $shop, string $ref, string $email): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $ref, $email): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->forceFill([
                'shop_id' => $shop->getKey(),
                'public_id' => 'PLN-'.$ref.'-'.uniqid(),
                'external_customer_id' => $ref,
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

    /** @param array<string, mixed> $body */
    private function signedPost(string $apiKey, string $apiSecret, string $path, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.$path.$json, $apiSecret, true));

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey, 'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }
}
