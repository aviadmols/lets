<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

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
    use Prunable;

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
     *
     * The channel is REQUIRED, not defaulted, because it decides how the
     * destination is folded first: an email lowercases, a phone number loses its
     * punctuation and its country code. A default here would silently hash
     * `050-123 4567` and `0501234567` to two different rows, which is exactly the
     * bug this argument exists to prevent — see PhoneNumber.
     */
    public static function hashDestination(int $shopId, string $channel, string $destination): string
    {
        return hash('sha256', $shopId.'|'.self::canonicalDestination($channel, $destination));
    }

    /**
     * The one spelling of a destination that every part of the flow agrees on.
     * An unparseable phone number falls back to its digits, so a shopper who
     * mistypes still gets a stable key for the throttle to count against.
     */
    public static function canonicalDestination(string $channel, string $destination): string
    {
        $destination = trim($destination);

        if ($channel !== self::CHANNEL_SMS) {
            return mb_strtolower($destination);
        }

        return PhoneNumber::canonical($destination)
            ?? PhoneNumber::digits($destination);
    }

    /**
     * A spent or expired code is evidence of nothing — the hashes are one-way and
     * the row cannot sign anyone in. Keeping a day of them makes an abuse report
     * answerable; keeping them forever turns a table the migration calls
     * short-lived into the largest one in the database.
     *
     * The prune legitimately spans shops — it runs from the scheduler with no
     * tenant bound — so it goes through acrossAllTenants(), the ONE named bypass
     * the isolation audit greps for. Never a raw withoutGlobalScopes(): that is
     * invisible to the audit and drops every future scope, not just the tenant's.
     */
    public function prunable(): Builder
    {
        return static::acrossAllTenants()
            ->where('expires_at', '<', now()->subDay());
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
