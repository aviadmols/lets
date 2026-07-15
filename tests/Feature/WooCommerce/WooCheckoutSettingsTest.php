<?php

namespace Tests\Feature\WooCommerce;

use App\Models\MerchantCheckoutSettings;
use App\Models\Shop;
use App\Services\PayPlus\PayPlusPageOptions;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * W15 — per-shop PayPlus payment-page options: the signed settings API the plugin uses, and the
 * PayPlusPageOptions translator that turns them into ALLOW-LISTED generateLink keys.
 *
 * Laws under test:
 *   - Auth: unsigned → 401.
 *   - Trust: every field is clamped/allow-listed server-side (the signed body is still input).
 *   - Tenant: a shop only ever reads/writes its own row.
 *   - Money-integrity: PayPlusPageOptions can only ever EMIT documented keys.
 */
final class WooCheckoutSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/woocommerce/checkout-settings';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_an_unsigned_settings_call_is_rejected_401(): void
    {
        $this->getJson(self::PATH)->assertStatus(401);
        $this->postJson(self::PATH, [])->assertStatus(401);
    }

    public function test_defaults_reproduce_todays_page_so_nothing_is_forced(): void
    {
        [$shop] = $this->connectedShop('cs-default.example.com');

        // A shop that never opened the form emits NOTHING new into generateLink.
        Tenant::run($shop, function () use ($shop): void {
            $this->assertSame([], array_diff_key(
                app(PayPlusPageOptions::class)->for($shop),
                // language_code + add_user_information are the only always-present keys, and
                // both equal PayPlus's own defaults.
                array_flip(['language_code', 'add_user_information']),
            ));
        });
    }

    public function test_saving_maps_and_clamps_every_field(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('cs-save.example.com');

        $this->signed('POST', $key, $secret, self::PATH, [
            'language_code' => 'en',
            'charge_default' => 'bit',
            'allowed_charge_methods' => ['bit', 'credit-card', 'NOT-A-METHOD'],
            'hide_other_charge_methods' => true,
            'max_payments' => 999,                 // clamp → ceiling
            'payments_selected' => 3,
            'create_token' => true,
        ])->assertOk()->assertJsonPath('settings.charge_default', 'bit');

        Tenant::run($shop, function () use ($shop): void {
            $opts = app(PayPlusPageOptions::class)->for($shop);

            $this->assertSame('en', $opts['language_code']);
            $this->assertSame('bit', $opts['charge_default']);
            // The garbage method was dropped; only documented ones survive.
            $this->assertSame(['bit', 'credit-card'], $opts['allowed_charge_methods']);
            $this->assertTrue($opts['hide_other_charge_methods']);
            $this->assertSame(MerchantCheckoutSettings::MAX_PAYMENTS, $opts['payments']); // clamped
            $this->assertSame(3, $opts['payments_selected']);
            $this->assertTrue($opts['create_token']);

            // Nothing outside the documented allow-list can ever be emitted.
            $this->assertEmpty(array_diff(array_keys($opts), PayPlusPageOptions::ALLOWED_KEYS));
        });
    }

    public function test_a_bogus_language_falls_back_and_a_bogus_method_is_dropped(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('cs-bogus.example.com');

        $this->signed('POST', $key, $secret, self::PATH, [
            'language_code' => 'zz',        // not a known language
            'charge_default' => 'bitcoin',  // not a documented method
        ])->assertOk();

        Tenant::run($shop, function () use ($shop): void {
            $opts = app(PayPlusPageOptions::class)->for($shop);
            $this->assertSame(MerchantCheckoutSettings::DEFAULT_LANGUAGE, $opts['language_code']);
            $this->assertArrayNotHasKey('charge_default', $opts); // rejected → not emitted
        });
    }

    public function test_settings_are_tenant_isolated(): void
    {
        [$shopA, $keyA, $secretA] = $this->connectedShop('cs-a.example.com');
        [$shopB] = $this->connectedShop('cs-b.example.com');

        $this->signed('POST', $keyA, $secretA, self::PATH, ['language_code' => 'en', 'create_token' => true]);

        Tenant::run($shopB, function () use ($shopB): void {
            // Shop B is untouched — still on defaults, token off.
            $this->assertFalse(MerchantCheckoutSettings::current()->createToken());
            $this->assertArrayNotHasKey('create_token', app(PayPlusPageOptions::class)->for($shopB));
        });
    }

    /** @return array{0:Shop,1:string,2:string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        [$key, $secret] = $this->keys($result['connection_token']);

        return [$result['shop']->fresh(), $key, $secret];
    }

    /** @param array<string,mixed> $body */
    private function signed(string $method, string $apiKey, string $apiSecret, string $path, array $body = []): TestResponse
    {
        $json = $method === 'GET' ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.$method.$path.$json, $apiSecret, true));

        return $this->call($method, $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey, 'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }

    /** @return array{0:string,1:string} */
    private function keys(string $token): array
    {
        $data = (array) json_decode((string) base64_decode(strtr($token, '-_', '+/')), true);

        return [(string) $data['k'], (string) $data['s']];
    }
}
