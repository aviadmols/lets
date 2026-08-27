<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * One shop's sending domain on the platform's SendGrid account.
 *
 * The row is a RECORD OF A CONVERSATION with the provider, not a setting: the
 * merchant asks for a domain, the provider answers with CNAMEs, the merchant
 * puts them in their DNS, and someone eventually confirms they are there. Every
 * column exists to make that conversation resumable — the records so the screen
 * can show them again without calling out, the status and `last_checked_at` so
 * the screen can say how old its answer is rather than implying it is live.
 *
 * `isUsable()` is the ONE question the mail path asks, and it is deliberately
 * strict: an unverified domain must never become a From address, because the
 * provider would refuse the message and, worse, a message that DID leave
 * unsigned teaches the receiving world that this domain sends unauthenticated
 * mail.
 */
class ShopSenderDomain extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'shop_sender_domains';

    /** Asked for, records issued, DNS not confirmed yet. */
    public const STATUS_PENDING = 'pending';

    /** The provider checked the DNS and signed off. Mail may go out as this domain. */
    public const STATUS_VERIFIED = 'verified';

    /** A check ran and the records were not (all) there. Recoverable — see `failure_reason`. */
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_VERIFIED, self::STATUS_FAILED];

    /** Longest domain we will accept; the RFC ceiling on a hostname. */
    public const MAX_DOMAIN = 253;

    /** shop_id is auto-stamped; status moves only through the service. */
    protected $guarded = ['id', 'shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'records' => 'array',
            'provider_domain_id' => 'integer',
            'last_checked_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The status, guarded, read from the raw attributes.
     *
     * The method shares its name with the column, and Eloquent's magic getter
     * falls through to relation resolution on a half-built model — the same trap
     * EmailCampaign::status() documents.
     */
    public function status(): string
    {
        $value = $this->attributes['status'] ?? null;

        return is_string($value) && in_array($value, self::STATUSES, true) ? $value : self::STATUS_PENDING;
    }

    public function isVerified(): bool
    {
        return $this->status() === self::STATUS_VERIFIED;
    }

    /** May the mail path send AS this domain? Verified and nothing else. */
    public function isUsable(): bool
    {
        return $this->isVerified() && $this->sendingDomain() !== '';
    }

    /**
     * The host a From address sits on: the authenticated subdomain when there is
     * one (mail.shop.co.il — what the CNAMEs actually sign), else the domain.
     */
    public function sendingDomain(): string
    {
        $domain = trim((string) $this->domain);
        if ($domain === '') {
            return '';
        }

        $subdomain = trim((string) $this->subdomain);

        return $subdomain !== '' ? $subdomain.'.'.$domain : $domain;
    }

    /**
     * The DNS records the merchant has to add, normalised to one shape the
     * screen can render — the provider returns them as an object of objects
     * whose keys vary with the account's settings.
     *
     * @return list<array{host: string, type: string, value: string, valid: bool}>
     */
    public function dnsRecords(): array
    {
        $out = [];

        foreach ((array) ($this->records ?? []) as $record) {
            if (! is_array($record)) {
                continue;
            }

            $host = trim((string) ($record['host'] ?? ''));
            $value = trim((string) ($record['data'] ?? $record['value'] ?? ''));

            if ($host === '' || $value === '') {
                continue;
            }

            $out[] = [
                'host' => $host,
                'type' => mb_strtoupper((string) ($record['type'] ?? 'CNAME')),
                'value' => $value,
                'valid' => (bool) ($record['valid'] ?? false),
            ];
        }

        return $out;
    }

    /** The row for one shop, read by id — correct on a worker with no tenant bound. */
    public static function forShop(int $shopId): ?self
    {
        return static::acrossAllTenants()->where('shop_id', $shopId)->first();
    }
}
