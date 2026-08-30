<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The platform's own sending account — ONE row, no tenant.
 *
 * Deliberately NOT a BelongsToShop model: this is the house's own credential,
 * and a global scope pinning it to whichever shop happens to be bound would
 * make it invisible exactly when a worker needs it.
 *
 * THE ENV VAR IS THE FALLBACK, NOT THE RIVAL. Every reader here answers "the
 * saved value, else the env one", so a deploy that has only variables keeps
 * working and an owner who saves a key on the screen is actually obeyed. The
 * key is encrypted at rest and never rendered back to the screen.
 *
 * The platform's OWN authenticated domain lives here as well — the same
 * conversation with the provider a shop has (SenderDomains), for the domain the
 * fallback From sits on. A `from_address` on an unauthenticated domain is the
 * one misconfiguration that makes EVERY shop's mail bounce, so the screen shows
 * its state rather than leaving the owner to find out from a failed send.
 */
class PlatformMailSettings extends Model
{
    // === CONSTANTS ===
    protected $table = 'platform_mail_settings';

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_VERIFIED, self::STATUS_FAILED];

    /** The label under a merchant's domain when the owner has not chosen one. */
    public const DEFAULT_SUBDOMAIN = 'mail';

    /** Which account the platform's mail leaves through. */
    public const PROVIDER_SENDGRID = 'sendgrid';

    public const PROVIDER_SES = 'ses';

    public const PROVIDERS = [self::PROVIDER_SENDGRID, self::PROVIDER_SES];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sendgrid_api_key' => 'encrypted',
            'ses_access_key_id' => 'encrypted',
            'ses_secret_access_key' => 'encrypted',
            'ses_smtp_username' => 'encrypted',
            'ses_smtp_password' => 'encrypted',
            'records' => 'array',
            // A provider handle is not always a number: SES names the domain.
            'provider_domain_id' => 'string',
            'last_checked_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The single row, created empty on first read.
     *
     * REFRESHED after a create, deliberately. A model that was just inserted
     * holds only the attributes that were set — none, here — so every column
     * with a database default is ABSENT from `$attributes`, and a raw read of
     * one falls through Eloquent's magic getter into relation resolution and
     * fatals. Re-reading the row costs one query, once, on the first call of
     * the platform's life.
     */
    public static function current(): self
    {
        $row = static::query()->first();

        if ($row !== null) {
            return $row;
        }

        $row = new self;
        $row->save();

        return $row->refresh();
    }

    /** The stored subdomain override, or null — the raw column, for the form. */
    public function subdomainOverride(): ?string
    {
        $value = trim((string) ($this->attributes['subdomain'] ?? ''));

        return $value !== '' ? $value : null;
    }

    // === The effective configuration (saved value, else env) ===

    /**
     * The key mail actually leaves with.
     *
     * Read through this and never off the column: a caller that read the column
     * would silently stop working on a deploy configured by variables alone.
     */
    public function apiKey(): ?string
    {
        return self::firstFilled(
            $this->sendgrid_api_key,
            config('services.sendgrid.api_key'),
        );
    }

    /** sendgrid | ses — read raw; the column shadows nothing but is defaulted. */
    public function provider(): string
    {
        $value = trim((string) ($this->attributes['provider'] ?? ''));

        return in_array($value, self::PROVIDERS, true) ? $value : self::PROVIDER_SENDGRID;
    }

    public function usesSes(): bool
    {
        return $this->provider() === self::PROVIDER_SES;
    }

    public function sesRegion(): ?string
    {
        return self::firstFilled($this->ses_region, config('services.ses.region'));
    }

    public function sesAccessKeyId(): ?string
    {
        return self::firstFilled($this->ses_access_key_id, config('services.ses.key'));
    }

    public function sesSecretAccessKey(): ?string
    {
        return self::firstFilled($this->ses_secret_access_key, config('services.ses.secret'));
    }

    /**
     * The SMTP pair, which on SES is NOT the API pair.
     *
     * AWS generates SMTP credentials separately and they can be revoked on
     * their own; treating one as the other is the most common way an SES setup
     * fails with a message that explains nothing.
     */
    public function sesSmtpUsername(): ?string
    {
        return self::firstFilled($this->ses_smtp_username, config('services.ses.smtp_username'));
    }

    public function sesSmtpPassword(): ?string
    {
        return self::firstFilled($this->ses_smtp_password, config('services.ses.smtp_password'));
    }

    /**
     * Can the platform send at all?
     *
     * Per provider, because "connected" means a different credential for each:
     * SendGrid needs its one key; SES needs a region and an SMTP pair to send,
     * whatever else is configured for the domain paperwork.
     */
    public function isConnected(): bool
    {
        if ($this->usesSes()) {
            return $this->sesRegion() !== null
                && $this->sesSmtpUsername() !== null
                && $this->sesSmtpPassword() !== null;
        }

        return $this->apiKey() !== null;
    }

    /** True when the owner typed the key here rather than into a deploy variable. */
    public function keyIsStored(): bool
    {
        return trim((string) $this->sendgrid_api_key) !== '';
    }

    public function fromAddress(): ?string
    {
        return self::firstFilled($this->from_address, config('services.sendgrid.from_address'));
    }

    public function fromName(): ?string
    {
        return self::firstFilled($this->from_name, config('services.sendgrid.from_name'));
    }

    /**
     * The raw read again (see status()): this method shares its name with the
     * column it reads, and Eloquent's magic getter falls through to relation
     * resolution rather than to the attribute — which fatals on a model whose
     * attributes are not all loaded.
     */
    public function subdomain(): string
    {
        return self::firstFilled(
            $this->attributes['subdomain'] ?? null,
            config('services.sendgrid.subdomain'),
        ) ?? self::DEFAULT_SUBDOMAIN;
    }

    // === The platform's own domain ===

    public function status(): string
    {
        $value = $this->attributes['status'] ?? null;

        return is_string($value) && in_array($value, self::STATUSES, true) ? $value : self::STATUS_PENDING;
    }

    public function domainIsVerified(): bool
    {
        return $this->status() === self::STATUS_VERIFIED;
    }

    /** The host the platform's own From sits on: mail.lets.co.il. */
    public function sendingDomain(): string
    {
        $domain = trim((string) $this->domain);
        if ($domain === '') {
            return '';
        }

        return $this->subdomain().'.'.$domain;
    }

    /**
     * Does the fallback From sit on the domain that was actually authenticated?
     *
     * The misconfiguration worth naming on the screen: a From on some other
     * domain is refused by the provider, and it is refused for EVERY shop that
     * has not verified one of its own — the whole platform's mail, from one
     * mistyped address.
     */
    public function fromMatchesDomain(): bool
    {
        $address = trim((string) $this->fromAddress());
        $at = strrpos($address, '@');

        if ($at === false || $at === 0) {
            return false;
        }

        $host = mb_strtolower(mb_substr($address, $at + 1));
        $domain = mb_strtolower(trim((string) $this->domain));

        if ($domain === '') {
            return false;
        }

        // The bare domain or anything under it: lets.co.il and mail.lets.co.il
        // are both signed by an authentication of lets.co.il.
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    /**
     * The DNS records the owner must publish, in the one shape the screen
     * renders — the same normalisation ShopSenderDomain applies.
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

    /** The first of these that carries anything, or null. */
    private static function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) ($value ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
