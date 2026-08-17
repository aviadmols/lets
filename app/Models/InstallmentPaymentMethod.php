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

    /**
     * Correct the card's LABEL from what PayPlus just told us about it.
     *
     * The expiry, brand and last four here are description, not credentials: the
     * charge rides `payplus_card_token_uid` and never reads any of them. They are
     * written once at vaulting — or copied from a migration file, which is how a
     * store arrives with dates that were already years old — and until now they
     * were never written again. Meanwhile the token keeps working straight
     * through a bank's renewal, which reissues the same card with a new date. So
     * the label drifts out of true while the card is perfectly chargeable, and a
     * merchant reads "expired" about somebody who paid this morning.
     *
     * This is the correction, and the only trustworthy moment for it: PayPlus has
     * just charged the card and is describing the card it charged. Nothing else
     * in the system can claim to know.
     *
     * Deliberately narrow:
     *  - only fields the response actually carried (an absent one never nulls a
     *    good stored value);
     *  - it SAVES only when something really changed, so a monthly cycle across
     *    thousands of subscribers does not become thousands of pointless writes;
     *  - it never touches the token, the status or the shop.
     *
     * @param  array{exp_month?: int, exp_year?: int, card_last_four?: string, card_brand?: string}  $card
     * @return bool true when the stored label was actually corrected
     */
    public function refreshLabelFrom(array $card): bool
    {
        $changed = [];

        foreach (['exp_month', 'exp_year', 'card_last_four', 'card_brand'] as $field) {
            if (! array_key_exists($field, $card)) {
                continue;
            }

            // Loose comparison on purpose: the column may hold "09" where the
            // gateway sends 9, and rewriting one as the other is not a change.
            if ((string) $this->{$field} !== (string) $card[$field]) {
                $changed[$field] = $card[$field];
            }
        }

        if ($changed === []) {
            return false;
        }

        $this->forceFill($changed)->save();

        return true;
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
