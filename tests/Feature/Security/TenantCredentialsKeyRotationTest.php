<?php

namespace Tests\Feature\Security;

use App\Console\Commands\RotateTenantCredentialsKey;
use App\Models\Shop;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MEDIUM-6 (2026-09 security audit): rotating TENANT_CREDENTIALS_KEY must not
 * silently disconnect every shop. `tenancy.previous_credentials_keys` lets
 * EncryptedCredentials::get() fall back to the key a bag was ACTUALLY encrypted
 * under, and `tenant:rotate-credentials-key` re-encrypts every readable bag onto
 * the current key so the previous one can eventually be retired.
 */
final class TenantCredentialsKeyRotationTest extends TestCase
{
    use RefreshDatabase;

    /** Exactly 32 bytes — AES-256-CBC's required raw key length. */
    private const OLD_KEY = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    protected function tearDown(): void
    {
        config(['tenancy.previous_credentials_keys' => []]);
        parent::tearDown();
    }

    private function shopEncryptedUnderOldKey(array $payload = ['api_key' => 'pk', 'secret_key' => 'sk']): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'old-key.example.com',
            'name' => 'Old Key Shop',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $ciphertext = (new Encrypter(self::OLD_KEY, 'AES-256-CBC'))->encryptString((string) json_encode($payload));
        DB::table('shops')->where('id', $shop->getKey())->update(['payplus_credentials' => $ciphertext]);

        return Shop::query()->findOrFail($shop->getKey());
    }

    public function test_a_bag_under_a_listed_previous_key_still_decrypts(): void
    {
        config(['tenancy.previous_credentials_keys' => [self::OLD_KEY]]);

        $shop = $this->shopEncryptedUnderOldKey(['api_key' => 'pk-1']);

        $this->assertSame('pk-1', $shop->payplus_credentials['api_key'] ?? null);
    }

    public function test_a_bag_under_an_unlisted_key_still_degrades_to_empty_not_throw(): void
    {
        // No previous key configured — exactly today's behaviour, still safe.
        $shop = $this->shopEncryptedUnderOldKey();

        $this->assertSame([], $shop->payplus_credentials);
    }

    public function test_the_rotate_command_re_encrypts_a_bag_onto_the_current_key(): void
    {
        config(['tenancy.previous_credentials_keys' => [self::OLD_KEY]]);
        $shop = $this->shopEncryptedUnderOldKey(['api_key' => 'pk-2']);

        $this->artisan(RotateTenantCredentialsKey::class)->assertSuccessful();

        // Drop the previous key entirely — a bag genuinely re-encrypted onto the
        // CURRENT key must still read back correctly with no fallback available.
        config(['tenancy.previous_credentials_keys' => []]);

        $fresh = Shop::query()->findOrFail($shop->getKey());
        $this->assertSame('pk-2', $fresh->payplus_credentials['api_key'] ?? null);
    }

    public function test_dry_run_writes_nothing(): void
    {
        config(['tenancy.previous_credentials_keys' => [self::OLD_KEY]]);
        $shop = $this->shopEncryptedUnderOldKey();
        $before = DB::table('shops')->where('id', $shop->getKey())->value('payplus_credentials');

        $this->artisan(RotateTenantCredentialsKey::class, ['--dry-run' => true])->assertSuccessful();

        $after = DB::table('shops')->where('id', $shop->getKey())->value('payplus_credentials');
        $this->assertSame($before, $after);
    }

    public function test_a_bag_unreadable_under_every_known_key_is_reported_not_silently_dropped(): void
    {
        // No previous key configured for THIS undecryptable bag — the rotate
        // command must fail loudly rather than quietly rewrite it as "empty".
        $this->shopEncryptedUnderOldKey();

        $this->artisan(RotateTenantCredentialsKey::class)->assertFailed();
    }

    public function test_a_shop_with_no_credentials_at_all_is_a_no_op(): void
    {
        Shop::create([
            'woocommerce_domain' => 'no-creds.example.com',
            'name' => 'No Creds',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $this->artisan(RotateTenantCredentialsKey::class)->assertSuccessful();
    }
}
