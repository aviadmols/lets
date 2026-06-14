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

    public function hasPayplusConnection(): bool
    {
        return ! empty($this->payplusCredential('api_key'))
            && ! empty($this->payplusCredential('secret_key'))
            && ! empty($this->payplusCredential('terminal_uid'));
    }

    // === Relations (tenant-owned models add the inverse via BelongsToShop) ===

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(\App\Models\PaymentLedger::class);
    }
}
