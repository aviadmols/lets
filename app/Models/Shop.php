<?php

namespace App\Models;

use App\Casts\EncryptedCredentials;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant. One row per installed Shopify store. Holds that store's OWN,
 * ENCRYPTED PayPlus + Shopify credentials. This is the only model NOT scoped by
 * BelongsToShop (it IS the shop). PayPlusGatewayFactory::for($shop) builds a
 * gateway bound to this shop's credentials.
 *
 * NOTE: scaffold stub — laravel-backend fleshes out relations, billing fields,
 * the credential accessors, and the install/uninstall lifecycle.
 */
class Shop extends Model
{
    // === CONSTANTS ===
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_UNINSTALLED = 'uninstalled';

    /**
     * Catalog source discriminator — which upstream this shop's products sync
     * FROM. The ProductSourceFactory switches on this. Shopify is the only live
     * source in Stage 1; WooCommerce is the Stage-2 placeholder.
     */
    public const PLATFORM_SHOPIFY = 'shopify';
    public const PLATFORM_WOOCOMMERCE = 'woocommerce';
    public const PLATFORMS = [self::PLATFORM_SHOPIFY, self::PLATFORM_WOOCOMMERCE];

    /** Statuses for which background charge dispatch + Shopify API calls are allowed. */
    public const LIVE_STATUSES = [self::STATUS_INSTALLED, self::STATUS_ACTIVE];

    /** PayPlus credential keys expected inside the encrypted bag. */
    public const PAYPLUS_KEYS = [
        'api_key', 'secret_key', 'terminal_uid', 'cashier_uid',
        'payment_page_uid', 'base_url', 'webhook_secret',
    ];

    /** WooCommerce REST credential keys expected inside the encrypted bag (W11). */
    public const WOOCOMMERCE_KEYS = ['base_url', 'consumer_key', 'consumer_secret', 'wc_webhook_secret'];

    protected $fillable = [
        'shopify_domain',
        'name',
        'platform',
        'status',
        'plan',
        'trial_ends_at',
        'shopify_access_token',
        'shopify_scopes',
        'installed_at',
        'uninstalled_at',
        'woocommerce_domain',
        'wc_shop_token',
        'lets_api_key_hash',
    ];

    protected $hidden = [
        'shopify_access_token',
        'payplus_credentials',
        'woocommerce_credentials',
        'lets_api_secret',
    ];

    protected function casts(): array
    {
        return [
            'payplus_credentials' => EncryptedCredentials::class,
            'woocommerce_credentials' => EncryptedCredentials::class,
            'shopify_access_token' => 'encrypted',
            'lets_api_secret' => 'encrypted',
            'trial_ends_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    // === Catalog source ===

    /**
     * The catalog platform discriminator (read as the property `$shop->platform`).
     * Defaults to Shopify when the column is null/blank or holds an unknown value
     * — covers rows created before the column existed and guards the
     * ProductSourceFactory switch against a bad value. The DB column stays the
     * source of truth; this only normalises the READ.
     */
    protected function platform(): Attribute
    {
        return Attribute::make(
            get: fn ($value): string => in_array((string) $value, self::PLATFORMS, true)
                ? (string) $value
                : self::PLATFORM_SHOPIFY,
        );
    }

    // === Shopify credentials + lifecycle ===

    /**
     * The decrypted Shopify offline access token. Read ONCE per job by
     * ShopifyClientFactory::for($shop) and held as in-process client state —
     * never read from config(), never logged, never shared across shops. Returns
     * null after uninstall (the token is revoked + nulled by AppUninstalledHandler).
     */
    public function shopifyAccessToken(): ?string
    {
        $token = $this->shopify_access_token; // 'encrypted' cast decrypts on read

        return ($token === null || $token === '') ? null : (string) $token;
    }

    public function hasShopifyConnection(): bool
    {
        return $this->shopifyAccessToken() !== null
            && in_array($this->status, self::LIVE_STATUSES, true);
    }

    /** Is this shop allowed to receive background charges + Shopify API calls? */
    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true)
            && $this->uninstalled_at === null;
    }

    /**
     * Mark the shop uninstalled and revoke its now-dead token. After uninstall the
     * Shopify token is invalid — every API call would 401 — so we null it and gate
     * the scheduler's due-charge query on shop.status (see AppUninstalledHandler).
     * Plans + ledger are PRESERVED (shop-scoped) for a possible reinstall.
     */
    public function markUninstalled(): void
    {
        $this->forceFill([
            'status' => self::STATUS_UNINSTALLED,
            'uninstalled_at' => now(),
            'shopify_access_token' => null,
        ])->save();
    }

    /**
     * Capture (or re-capture on reinstall) the offline OAuth token. Matched by
     * shopify_domain upstream so reinstall never duplicates the Shop row.
     */
    public function captureShopifyInstall(string $accessToken, ?string $scopes): void
    {
        $this->forceFill([
            'shopify_access_token' => $accessToken,   // 'encrypted' cast encrypts on write
            'shopify_scopes' => $scopes,
            'status' => self::STATUS_INSTALLED,
            'installed_at' => now(),
            'uninstalled_at' => null,
        ])->save();
    }

    /** Read a single PayPlus credential from the encrypted bag. */
    public function payplusCredential(string $key): ?string
    {
        return $this->payplus_credentials[$key] ?? null;
    }

    /**
     * The decrypted PayPlus credential bag in the exact shape the reference
     * engine read from config('payplus_installments.payplus.*'). The factory
     * decrypts ONCE per job and hands this to the gateway as constructor state —
     * never read from config() at call time, never shared across shops.
     */
    public function payplusConfig(): array
    {
        $bag = $this->payplus_credentials ?: [];

        return [
            'api_key' => $bag['api_key'] ?? null,
            'secret_key' => $bag['secret_key'] ?? null,
            'terminal_uid' => $bag['terminal_uid'] ?? null,
            'cashier_uid' => $bag['cashier_uid'] ?? null,
            'payment_page_uid' => $bag['payment_page_uid'] ?? null,
            'webhook_secret' => $bag['webhook_secret'] ?? null,
            // Per-shop base_url override; falls back to the platform default.
            'base_url' => $bag['base_url'] ?: config('payplus.base_url'),
        ];
    }

    public function hasPayplusConnection(): bool
    {
        return ! empty($this->payplusCredential('api_key'))
            && ! empty($this->payplusCredential('secret_key'))
            && ! empty($this->payplusCredential('terminal_uid'));
    }

    // === WooCommerce credentials (W11) ===

    /** Read a single WooCommerce REST credential from the encrypted bag. */
    public function wooCredential(string $key): ?string
    {
        return $this->woocommerce_credentials[$key] ?? null;
    }

    /**
     * The decrypted WooCommerce REST credential bag (base_url + consumer key/secret +
     * the per-shop wc_webhook_secret). Mirrors payplusConfig() — decrypted per request,
     * never read from config(), never shared across shops.
     */
    public function wooConfig(): array
    {
        $bag = $this->woocommerce_credentials ?: [];

        return [
            'base_url' => $bag['base_url'] ?? null,
            'consumer_key' => $bag['consumer_key'] ?? null,
            'consumer_secret' => $bag['consumer_secret'] ?? null,
            'wc_webhook_secret' => $bag['wc_webhook_secret'] ?? null,
        ];
    }

    /** Has the WooCommerce plugin completed the connect handshake (REST creds present)? */
    public function hasWooConnection(): bool
    {
        return ! empty($this->wooCredential('consumer_key'))
            && ! empty($this->wooCredential('consumer_secret'));
    }

    // === Relations (tenant-owned models add the inverse via BelongsToShop) ===

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(PaymentLedger::class);
    }

    public function installmentPlans(): HasMany
    {
        return $this->hasMany(InstallmentPlan::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(InstallmentPaymentMethod::class);
    }

    /** The local products cache for this shop (synced from its source). */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** The per-product/variant subscription plan templates for this shop. */
    public function productSubscriptionPlans(): HasMany
    {
        return $this->hasMany(ProductSubscriptionPlan::class);
    }
}
