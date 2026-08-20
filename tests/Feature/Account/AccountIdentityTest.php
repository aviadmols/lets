<?php

namespace Tests\Feature\Account;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The known-member lookup behind quick sign-in: a shopper who just proved an
 * address by code is either a stranger (register form) or a member LETS has
 * been billing for a year (account opened for them). The endpoint answers with
 * a NAME and an EMAIL and nothing else — it hands out nothing a receipt would
 * not, because it cannot itself verify that the code round happened.
 */
final class AccountIdentityTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const PATH = '/api/woocommerce/account/identity';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_member_is_recognized_by_email_with_their_name(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('identity-email.example.com');
        $this->member($shop, email: 'aviad+45@example.com', name: 'אביעד כהן', phone: '+972-50-123-4567');

        $this->signedPost($key, $secret, self::PATH, [
            'channel' => 'email',
            'destination' => 'Aviad+45@Example.com', // case must not matter
        ])->assertOk()->assertJson([
            'ok' => true,
            'known' => true,
            'first_name' => 'אביעד',
            'last_name' => 'כהן',
            'email' => 'aviad+45@example.com',
        ]);
    }

    public function test_a_member_is_recognized_by_phone_across_formats(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('identity-sms.example.com');
        $this->member($shop, email: 'dana@example.com', name: 'דנה לוי', phone: '050-123 4567');

        // The shopper types the international form; the plan stores the local one.
        $this->signedPost($key, $secret, self::PATH, [
            'channel' => 'sms',
            'destination' => '+972501234567',
        ])->assertOk()->assertJson([
            'ok' => true,
            'known' => true,
            'first_name' => 'דנה',
            'email' => 'dana@example.com',
        ]);
    }

    public function test_a_stranger_is_not_known_and_nothing_leaks(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('identity-stranger.example.com');
        $this->member($shop, email: 'member@example.com', name: 'חבר מועדון', phone: '0501234567');

        $response = $this->signedPost($key, $secret, self::PATH, [
            'channel' => 'email',
            'destination' => 'nobody@example.com',
        ])->assertOk()->assertJson(['ok' => true, 'known' => false]);

        $this->assertArrayNotHasKey('email', (array) $response->json());
        $this->assertArrayNotHasKey('first_name', (array) $response->json());
    }

    public function test_another_shops_member_is_a_stranger_here(): void
    {
        [, $keyA, $secretA] = $this->connectedShop('identity-a.example.com');
        [$shopB] = $this->connectedShop('identity-b.example.com');
        $this->member($shopB, email: 'b-member@example.com', name: 'לקוח של ב', phone: '0509999999');

        $this->signedPost($keyA, $secretA, self::PATH, [
            'channel' => 'email',
            'destination' => 'b-member@example.com',
        ])->assertOk()->assertJson(['ok' => true, 'known' => false]);
    }

    public function test_an_unsigned_call_is_rejected(): void
    {
        $this->postJson(self::PATH, ['channel' => 'email', 'destination' => 'x@example.com'])
            ->assertStatus(401);
    }

    // === Helpers ===

    private function member(Shop $shop, string $email, string $name, string $phone): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($email, $name, $phone): InstallmentPlan {
            $plan = new InstallmentPlan([
                'public_id' => 'PLN-'.uniqid(),
                'plan_kind' => 'recurring',
                'charge_context' => 'recurring',
                'customer_id' => null,
                // The imported shape: a legacy UUID reference, identity on the plan.
                'shopify_customer_id' => (string) Str::uuid(),
                'customer_email' => $email,
                'customer_name' => $name,
                'customer_phone' => $phone,
                'total_amount' => 100,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'billing_frequency' => 'yearly',
                'interval_count' => 1,
            ]);
            $plan->forceFill(['shop_id' => Tenant::id(), 'status' => 'active'])->save();

            return $plan->fresh();
        });
    }

    /** @return array{0: Shop, 1: string, 2: string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];

        $json = (string) base64_decode(strtr($result['connection_token'], '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [$shop->fresh(), (string) $data['k'], (string) $data['s']];
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
