<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * The vault: a saved PayPlus card token for a customer. This is what makes
 * one-click recurring/installment/upsell charges possible — chargeWithReference
 * uses payplus_card_token_uid with use_token=true, no card re-entry. The token
 * UID itself is encrypted at rest. Tenant-scoped.
 *
 * Source: app/Modules/PayPlusShopifyInstallments/Models/InstallmentPaymentMethod.php
 */
class InstallmentPaymentMethod extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'installment_payment_methods';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    protected $guarded = [];

    protected $hidden = [
        'payplus_card_token_uid',
        'encrypted_payplus_token',
    ];

    protected function casts(): array
    {
        return [
            // The token UID is a credential — encrypt at rest (APP_KEY cast is
            // fine here; it is not a cross-shop secret, it is per-row).
            'payplus_card_token_uid' => 'encrypted',
            'exp_month' => 'integer',
            'exp_year' => 'integer',
        ];
    }

    /**
     * The decrypted raw token, the final link in the gateway's token fallback
     * chain (payplus_card_token_uid ?: payplus_token_reference ?: rawToken).
     * Ported verbatim from the reference engine's InstallmentPaymentMethod so
     * chargeWithReference() reads the exact same shape it was paid for.
     */
    protected function rawToken(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->encrypted_payplus_token === null
                ? null
                : Crypt::decryptString($this->encrypted_payplus_token),
            set: fn (?string $value): array => [
                'encrypted_payplus_token' => $value === null ? null : Crypt::encryptString($value),
            ],
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function plans(): HasMany
    {
        return $this->hasMany(InstallmentPlan::class, 'payment_method_id');
    }
}
