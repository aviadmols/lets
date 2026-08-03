<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time sign-in code for the shopper's personal area.
 *
 * Nothing readable is stored. The code is password-hashed, so a database read
 * cannot sign anyone in; the destination is sha256'd with the shop id, so this
 * table is not a harvestable list of the shop's emails and phone numbers, and one
 * shop's hashes cannot be matched against another's.
 *
 * `attempts` lives on the row rather than in a cache: a cache flush must not hand a
 * brute-forcer a fresh budget, and the row is the audit record of how many guesses
 * were spent against that code.
 */
class CustomerLoginCode extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'customer_login_codes';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNELS = [self::CHANNEL_EMAIL, self::CHANNEL_SMS];

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['code_hash', 'destination_hash', 'ip_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * The lookup key. Salted with the shop id so the same address on two shops
     * produces two different hashes — one merchant's table can never be used to
     * confirm whether an address exists on another's.
     */
    public static function hashDestination(int $shopId, string $destination): string
    {
        return hash('sha256', $shopId.'|'.mb_strtolower(trim($destination)));
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /** Still worth checking a guess against? */
    public function isLive(int $maxAttempts): bool
    {
        return ! $this->isExpired()
            && ! $this->isConsumed()
            && $this->attempts < $maxAttempts;
    }
}
