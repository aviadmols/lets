<?php

namespace Tests\Feature\Platform;

use App\Filament\Resources\ShopResource\Pages\ViewShop;
use App\Models\Shop;
use App\Models\User;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Production shop 2 had woocommerce_credentials encrypted under an OLD
 * TENANT_CREDENTIALS_KEY → every read of the bag threw DecryptException ("The MAC is
 * invalid"), which 500'd the shop detail page AND the "Connection token" mint (mintToken
 * reads the bag to merge base_url). The EncryptedCredentials cast now degrades an
 * undecryptable bag to [] (logged) so reads are safe, and re-minting overwrites the bag
 * with a fresh, decryptable ciphertext — repairing the corruption.
 */
final class EncryptedCredentialsResilienceTest extends TestCase
{
    use RefreshDatabase;

    /** Ciphertext encrypted under a DIFFERENT key than the app's → fails the MAC on read. */
    private function foreignCiphertext(array $payload): string
    {
        return (new Encrypter(random_bytes(32), 'AES-256-CBC'))
            ->encryptString((string) json_encode($payload));
    }

    private function wooShopWithCorruptCreds(): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'store.example.com',
            'name' => 'WC',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        // Write a foreign-key ciphertext straight to the column (bypass the cast's set()).
        DB::table('shops')->where('id', $shop->getKey())->update([
            'woocommerce_credentials' => $this->foreignCiphertext([
                'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'base_url' => 'https://store.example.com',
            ]),
        ]);

        return Shop::query()->findOrFail($shop->getKey());
    }

    public function test_undecryptable_bag_reads_as_empty_not_throw(): void
    {
        $shop = $this->wooShopWithCorruptCreds();

        // The decrypt fails internally but the cast degrades to [] — no DecryptException.
        $this->assertSame([], $shop->woocommerce_credentials);
        $this->assertFalse($shop->hasWooConnection());
    }

    public function test_mint_token_repairs_corrupt_credentials(): void
    {
        $shop = $this->wooShopWithCorruptCreds();

        $token = app(WooCommerceShopProvisioner::class)->mintToken($shop);

        $this->assertNotEmpty($token);

        // The bag is now freshly encrypted (decryptable) with base_url set, and a fresh
        // key hash is stored — the merchant pastes the new token and reconnects.
        $fresh = Shop::query()->findOrFail($shop->getKey());
        $this->assertSame('https://store.example.com', $fresh->woocommerce_credentials['base_url'] ?? null);
        $this->assertNotNull($fresh->lets_api_key_hash);
    }

    public function test_shop_detail_renders_with_corrupt_creds(): void
    {
        $this->withoutExceptionHandling();

        $shop = $this->wooShopWithCorruptCreds();
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->get(ViewShop::getUrl(['record' => $shop->getKey()]))
            ->assertOk();
    }
}
