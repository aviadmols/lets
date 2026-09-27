<?php

namespace Tests\Feature\WooCommerce;

use App\Http\Controllers\WooCommerce\InstallController;
use App\Jobs\Products\ImportShopProductsJob;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The WooCommerce plugin → SaaS connect handshake (completes the onboarding loop). The
 * plugin signs HMAC-SHA256(ts + METHOD + path + rawBody, api_secret); the SaaS looks
 * the shop up by sha256(api_key) and verifies. A valid request connects the store
 * (stores creds + a webhook secret, mints a wc_shop_token); a forged/stale/unknown
 * request is rejected 401; a host that doesn't match the minted-for domain is 422.
 */
final class WooCommerceInstallTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/woocommerce/install';

    public function test_a_validly_signed_request_connects_the_store(): void
    {
        $result = (new WooCommerceShopProvisioner)->provision('store.example.com');
        [$key, $secret] = $this->keys($result['connection_token']);

        $response = $this->signedPost($key, $secret, ['base_url' => 'https://store.example.com', 'plugin_version' => '0.1.0']);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertNotEmpty($response->json('wc_webhook_secret'));

        $shop = $result['shop']->fresh();
        $this->assertNotNull($shop->wooCredential('wc_webhook_secret'));
        $this->assertNotNull($shop->wc_shop_token);
        $this->assertSame('https://store.example.com', $shop->wooCredential('base_url'));
    }

    public function test_install_with_wc_keys_dispatches_the_product_import(): void
    {
        Queue::fake();
        $result = (new WooCommerceShopProvisioner)->provision('store.example.com');
        [$key, $secret] = $this->keys($result['connection_token']);

        $this->signedPost($key, $secret, [
            'base_url' => 'https://store.example.com',
            'consumer_key' => 'ck_x',
            'consumer_secret' => 'cs_x',
        ])->assertOk()->assertJsonPath('products_syncing', true);

        Queue::assertPushed(ImportShopProductsJob::class);
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $result = (new WooCommerceShopProvisioner)->provision('store.example.com');
        [$key] = $this->keys($result['connection_token']);

        $this->signedPost($key, 'WRONG_SECRET', ['base_url' => 'https://store.example.com'])->assertStatus(401);
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        $result = (new WooCommerceShopProvisioner)->provision('store.example.com');
        [$key, $secret] = $this->keys($result['connection_token']);

        $this->signedPost($key, $secret, ['base_url' => 'https://store.example.com'], tsOverride: (string) (time() - 9999))
            ->assertStatus(401);
    }

    public function test_an_unknown_key_is_rejected(): void
    {
        $this->signedPost('lets_unknown_key', 'whatever', ['base_url' => 'https://x.example.com'])->assertStatus(401);
    }

    public function test_a_host_that_does_not_match_the_minted_domain_is_rejected(): void
    {
        $result = (new WooCommerceShopProvisioner)->provision('store.example.com');
        [$key, $secret] = $this->keys($result['connection_token']);

        // A valid signature, but the plugin reports a DIFFERENT site than the token's.
        $this->signedPost($key, $secret, ['base_url' => 'https://attacker.example.com'])->assertStatus(422);
    }

    public function test_a_base_url_that_normalizes_to_nothing_is_refused_not_skipped(): void
    {
        $result = (new WooCommerceShopProvisioner)->provision('store.example.com');
        [$key, $secret] = $this->keys($result['connection_token']);

        // Each of these used to slip past the domain check (parse_url yields no
        // host, so the check was skipped) and was then stored verbatim.
        foreach (['javascript:alert(document.domain)//x', 'https:///evil.com', 'https://javascript:alert(1)'] as $bad) {
            $this->signedPost($key, $secret, ['base_url' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('error', InstallController::ERROR_INVALID_BASE_URL);
        }

        $this->assertNull($result['shop']->fresh()->wc_shop_token);
    }

    public function test_an_insecure_or_decorated_base_url_is_refused(): void
    {
        $result = (new WooCommerceShopProvisioner)->provision('store.example.com');
        [$key, $secret] = $this->keys($result['connection_token']);

        foreach (['http://store.example.com', 'https://store.example.com/?q=', 'https://store.example.com:8443'] as $bad) {
            $this->signedPost($key, $secret, ['base_url' => $bad])->assertStatus(422);
        }
    }

    public function test_the_stored_base_url_is_the_canonical_form(): void
    {
        $result = (new WooCommerceShopProvisioner)->provision('store.example.com');
        [$key, $secret] = $this->keys($result['connection_token']);

        $this->signedPost($key, $secret, ['base_url' => 'https://www.store.example.com/shop/'])->assertOk();

        $this->assertSame('https://www.store.example.com/shop', $result['shop']->fresh()->wooCredential('base_url'));
    }

    public function test_a_shop_without_a_minted_domain_cannot_connect(): void
    {
        $result = (new WooCommerceShopProvisioner)->provision('store.example.com');
        [$key, $secret] = $this->keys($result['connection_token']);
        $result['shop']->forceFill(['woocommerce_domain' => null])->save();

        $this->signedPost($key, $secret, ['base_url' => 'https://anything.example.com'])
            ->assertStatus(422)
            ->assertJsonPath('error', InstallController::ERROR_NO_DOMAIN);
    }

    // === Helpers ===

    /**
     * @param  array<string,mixed>  $body
     */
    private function signedPost(string $apiKey, string $apiSecret, array $body, ?string $tsOverride = null): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = $tsOverride ?? (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.self::PATH.$json, $apiSecret, true));

        return $this->call('POST', self::PATH, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey,
            'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $json);
    }

    /** @return array{0:string,1:string} [api_key, api_secret] from the connection token */
    private function keys(string $token): array
    {
        $json = (string) base64_decode(strtr($token, '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [(string) $data['k'], (string) $data['s']];
    }
}
