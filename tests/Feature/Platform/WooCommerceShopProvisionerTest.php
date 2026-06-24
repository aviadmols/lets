<?php

namespace Tests\Feature\Platform;

use App\Models\Shop;
use App\Models\User;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * W11 Phase 1 — admin-driven WooCommerce onboarding. The provisioner mints a single
 * connection token: the PLAINTEXT api_key lives only inside the token; the shop stores
 * only its sha256 hash (lookup) + the encrypted secret (the HMAC key the plugin signs
 * with). Re-provisioning a domain never duplicates the shop; re-minting invalidates the
 * old token.
 */
final class WooCommerceShopProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private function provisioner(): WooCommerceShopProvisioner
    {
        return new WooCommerceShopProvisioner;
    }

    public function test_provision_creates_a_woocommerce_shop_with_a_normalized_domain(): void
    {
        $result = $this->provisioner()->provision('https://WWW.Store.Example.com/shop/', 'My Store');

        $shop = $result['shop'];
        $this->assertSame('store.example.com', $shop->woocommerce_domain);
        $this->assertSame('store.example.com', $result['domain']);
        $this->assertSame(Shop::PLATFORM_WOOCOMMERCE, $shop->platform);
        $this->assertSame('My Store', $shop->name);
        $this->assertSame(Shop::STATUS_INSTALLED, $shop->status);
        $this->assertNull($shop->shopify_domain);
    }

    public function test_token_carries_the_key_and_the_shop_stores_only_its_hash_plus_secret(): void
    {
        $result = $this->provisioner()->provision('store.example.com');
        $shop = $result['shop'];

        $this->assertNotNull($shop->lets_api_key_hash);
        $this->assertSame(64, strlen($shop->lets_api_key_hash)); // sha256 hex
        $this->assertNotNull($shop->lets_api_secret);

        $decoded = $this->decode($result['connection_token']);
        $this->assertStringStartsWith(WooCommerceShopProvisioner::API_KEY_PREFIX, $decoded['k']);
        // The hash stored equals sha256 of the key carried in the token.
        $this->assertSame(hash('sha256', $decoded['k']), $shop->lets_api_key_hash);
        // The secret in the token equals the decrypted stored secret.
        $this->assertSame($shop->lets_api_secret, $decoded['s']);
        $this->assertSame('store.example.com', $decoded['d']);
        $this->assertStringContainsString('/api/woocommerce/install', $decoded['u']);
        $this->assertSame(WooCommerceShopProvisioner::TOKEN_VERSION, $decoded['v']);
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        $result = $this->provisioner()->provision('enc.example.com');
        $decoded = $this->decode($result['connection_token']);

        $raw = (string) DB::table('shops')->where('id', $result['shop']->getKey())->value('lets_api_secret');
        $this->assertStringNotContainsString($decoded['s'], $raw);
    }

    public function test_reminting_invalidates_the_previous_token(): void
    {
        $result = $this->provisioner()->provision('remint.example.com');
        $firstHash = $result['shop']->lets_api_key_hash;

        $newToken = $this->provisioner()->mintToken($result['shop']->fresh());

        $this->assertNotSame($firstHash, $result['shop']->fresh()->lets_api_key_hash);
        $this->assertSame(hash('sha256', $this->decode($newToken)['k']), $result['shop']->fresh()->lets_api_key_hash);
    }

    public function test_re_provisioning_the_same_domain_does_not_duplicate_the_shop(): void
    {
        $this->provisioner()->provision('dup.example.com', 'First');
        $this->provisioner()->provision('dup.example.com', 'Second');

        $this->assertSame(1, Shop::query()->where('woocommerce_domain', 'dup.example.com')->count());
    }

    public function test_provision_with_an_email_creates_a_claimable_merchant_login(): void
    {
        $result = $this->provisioner()->provision('login.example.com', null, 'merchant@example.com');

        $user = User::query()->where('shop_id', $result['shop']->getKey())->first();
        $this->assertNotNull($user);
        $this->assertSame('merchant@example.com', $user->email);
    }

    public function test_provision_without_an_email_creates_no_login(): void
    {
        $result = $this->provisioner()->provision('nologin.example.com');

        $this->assertSame(0, User::query()->where('shop_id', $result['shop']->getKey())->count());
    }

    /** @return array<string,mixed> */
    private function decode(string $token): array
    {
        $json = (string) base64_decode(strtr($token, '-_', '+/'));

        return (array) json_decode($json, true);
    }
}
