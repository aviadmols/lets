<?php

namespace App\Services\WooCommerce;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Admin-driven WooCommerce onboarding (W11). The platform admin creates a WooCommerce
 * shop from /admin/shops by entering its domain; this service:
 *   1. creates/finds the Shop by its normalized domain (platform=woocommerce),
 *   2. mints a fresh connection key + secret — the PLAINTEXT key is returned ONCE
 *      inside the connection token; only its sha256 hash (lookup) + the ENCRYPTED
 *      secret (the HMAC signing key) are stored, never the plaintext key,
 *   3. optionally provisions a claimable merchant login,
 *   4. returns the single connection token the merchant pastes into the plugin + the
 *      plugin download URL.
 *
 * The plugin signs every server→SaaS call with HMAC-SHA256(timestamp+method+path+body,
 * api_secret); VerifyWooCommerceSignature looks the shop up by sha256(api_key) ==
 * lets_api_key_hash and verifies with the decrypted secret. Tenant-safe: a token can
 * only ever connect the one shop it was minted for.
 */
final class WooCommerceShopProvisioner
{
    // === CONSTANTS ===
    /** Prefix on the connection api_key so it is recognizable in plugin settings/logs. */
    public const API_KEY_PREFIX = 'lets_';

    /** Connection-token schema version (the plugin reads `v` to stay forward-compatible). */
    public const TOKEN_VERSION = 1;

    /**
     * Provision a WooCommerce shop and return its connection token + plugin URL.
     *
     * @return array{shop: Shop, connection_token: string, plugin_url: string, domain: string}
     */
    public function provision(string $rawDomain, ?string $name = null, ?string $merchantEmail = null): array
    {
        $domain = $this->normalizeDomain($rawDomain);
        if ($domain === '') {
            throw new \InvalidArgumentException('A valid WooCommerce store domain is required.');
        }

        $shop = Shop::query()->firstOrNew(['woocommerce_domain' => $domain]);
        $isNew = ! $shop->exists;
        $shop->forceFill([
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'name' => $name ?: ($shop->name ?: $domain),
            'status' => Shop::STATUS_INSTALLED,
        ]);
        if ($isNew) {
            $shop->installed_at = now();
        }
        $shop->save();

        $token = $this->mintToken($shop);

        if ($merchantEmail !== null && trim($merchantEmail) !== '') {
            $this->provisionMerchant($shop, trim($merchantEmail));
        }

        return [
            'shop' => $shop->refresh(),
            'connection_token' => $token,
            'plugin_url' => route('woocommerce.plugin.download'),
            'domain' => $domain,
        ];
    }

    /**
     * Mint (or RE-mint) the connection key/secret for a shop and return the single
     * connection token. Re-minting invalidates the previous token (a new key hash +
     * secret). The plaintext key lives only in the returned token.
     */
    public function mintToken(Shop $shop): string
    {
        $apiKey = self::API_KEY_PREFIX.Str::random(40);
        $apiSecret = Str::random(64);

        $shop->lets_api_key_hash = hash('sha256', $apiKey);
        $shop->lets_api_secret = $apiSecret; // encrypted cast on the model
        $shop->woocommerce_credentials = array_merge($shop->woocommerce_credentials ?: [], [
            'base_url' => 'https://'.$shop->woocommerce_domain,
        ]);
        $shop->save();

        return $this->buildConnectionToken($apiKey, $apiSecret, (string) $shop->woocommerce_domain);
    }

    /** Normalize "https://WWW.Store.example.com/shop/" → "store.example.com". */
    public function normalizeDomain(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // Give parse_url a scheme so it extracts the host (not the path).
        $withScheme = preg_match('#^https?://#i', $raw) === 1 ? $raw : 'https://'.$raw;
        $host = strtolower((string) (parse_url($withScheme, PHP_URL_HOST) ?: ''));

        return (string) preg_replace('#^www\.#', '', $host);
    }

    /** Provision a claimable merchant login (real email → password-reset claim). Idempotent. */
    private function provisionMerchant(Shop $shop, string $email): User
    {
        $existing = User::query()->where('shop_id', $shop->getKey())->first();
        if ($existing !== null) {
            return $existing;
        }

        $byEmail = User::query()->where('email', $email)->first();
        if ($byEmail !== null) {
            if ($byEmail->shop_id === null) {
                $byEmail->forceFill(['shop_id' => $shop->getKey()])->save();
            }

            return $byEmail;
        }

        return User::create([
            'name' => $shop->name ?: $shop->woocommerce_domain,
            'email' => $email,
            // Placeholder; the merchant claims it via password reset.
            'password' => Hash::make(Str::random(40)),
            'shop_id' => $shop->getKey(),
        ]);
    }

    /** base64url(json {k,s,u,d,v}) — the single string the plugin decodes into key/secret/url/domain. */
    private function buildConnectionToken(string $apiKey, string $apiSecret, string $domain): string
    {
        $payload = (string) json_encode([
            'k' => $apiKey,
            's' => $apiSecret,
            'u' => url('/api/woocommerce/install'),
            'd' => $domain,
            'v' => self::TOKEN_VERSION,
        ], JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }
}
