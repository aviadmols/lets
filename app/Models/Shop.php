<?php

namespace App\Models;

use App\Casts\EncryptedCredentials;
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
    public const STATUS_UNINSTALLED = 'uninstalled';

    /** PayPlus credential keys expected inside the encrypted bag. */
    public const PAYPLUS_KEYS = [
        'api_key', 'secret_key', 'terminal_uid', 'cashier_uid',
        'payment_page_uid', 'base_url', 'webhook_secret',
    ];

    protected $fillable = [
        'shopify_domain',
        'name',
        'status',
        'plan',
        'trial_ends_at',
    ];

    protected $hidden = [
        'shopify_access_token',
        'payplus_credentials',
    ];

    protected function casts(): array
    {
        return [
            'payplus_credentials' => EncryptedCredentials::class,
            'shopify_access_token' => 'encrypted',
            'trial_ends_at' => 'datetime',
        ];
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
}
