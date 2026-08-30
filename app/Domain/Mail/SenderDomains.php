<?php

namespace App\Domain\Mail;

use App\Domain\Mail\SendGrid\SendGridClient;
use App\Models\Shop;
use App\Models\ShopSenderDomain;
use App\Support\Tenant;

/**
 * A merchant's own sending domain, from "I want my mail to come from my domain"
 * to mail actually leaving signed as it.
 *
 * THE PLATFORM STAYS THE ACCOUNT HOLDER. Every domain here is authenticated on
 * OUR SendGrid account; a merchant never holds a provider key and never sees
 * one. What they get is the short list of CNAMEs to publish, and a button that
 * answers "are they there yet".
 *
 * TWO CHECKS, DELIBERATELY. `check()` reads the merchant's DNS DIRECTLY first
 * and only then asks the provider. The provider's answer is the one that
 * counts — it is the party that will refuse to sign — but it is also cached,
 * rate-limited and silent about which record is missing. Resolving the CNAMEs
 * ourselves turns "not verified" into "this host does not resolve yet", which
 * is the difference between a merchant fixing one line and a merchant guessing.
 * DNS propagation is why an answer can be "not yet" three times and then yes.
 *
 * NOTHING HERE THROWS. A provider outage or a broken resolver leaves the row
 * where it was with a reason on it; a settings screen must not 500 because a
 * third party is slow.
 */
final class SenderDomains
{
    // === CONSTANTS ===
    /** Reason codes; the screen translates each one. */
    public const REASON_NOT_CONFIGURED = 'not_configured';

    public const REASON_PROVIDER_UNREACHABLE = 'provider_unreachable';

    /** The provider answered — and refused the key. Not an outage. */
    public const REASON_PROVIDER_UNAUTHORIZED = 'provider_unauthorized';

    public const REASON_RECORDS_MISSING = 'records_missing';

    public const REASON_INVALID_DOMAIN = 'invalid_domain';

    /** A hostname, without a scheme, a path, or a leading label of our own. */
    private const DOMAIN_PATTERN = '/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/';

    public function __construct(private readonly SendGridClient $client = new SendGridClient) {}

    /**
     * Ask the provider to authenticate a domain for this shop, and store the
     * records the merchant must publish.
     *
     * Re-requesting REPLACES the previous domain, provider row included: a shop
     * that typed the wrong domain must not leave an orphan authenticated on our
     * account, and two half-verified domains would make "which are we sending
     * as" unanswerable.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function request(Shop $shop, string $domain): array
    {
        if (! SendGridClient::configured()) {
            return $this->fail(self::REASON_NOT_CONFIGURED);
        }

        $domain = self::normaliseDomain($domain);
        if ($domain === null) {
            return $this->fail(self::REASON_INVALID_DOMAIN);
        }

        $existing = ShopSenderDomain::forShop((int) $shop->getKey());

        // Same domain, already asked for: hand back what we have rather than
        // authenticating a second copy of it on the account.
        if ($existing !== null && $existing->domain === $domain && $existing->provider_domain_id !== null) {
            return $this->ok();
        }

        $created = $this->client->authenticateDomain($domain);
        if ($created === null) {
            return $this->fail($this->providerFailureReason());
        }

        // The old one goes only AFTER the new one exists — a provider that
        // refuses the new domain must not leave the shop with neither.
        if ($existing !== null && $existing->provider_domain_id !== null) {
            $this->client->removeDomain((int) $existing->provider_domain_id);
        }

        $row = $existing ?? new ShopSenderDomain;
        $row->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'domain' => $domain,
            'subdomain' => $created['subdomain'],
            'provider_domain_id' => $created['id'],
            'status' => ShopSenderDomain::STATUS_PENDING,
            'records' => $created['records'],
            'failure_reason' => null,
            'last_checked_at' => null,
            'verified_at' => null,
        ])->save();

        return $this->ok();
    }

    /**
     * Check whether the records are live, and move the row accordingly.
     *
     * @return array{ok: bool, reason: ?string, records: list<array<string, mixed>>}
     */
    public function check(Shop $shop): array
    {
        $row = ShopSenderDomain::forShop((int) $shop->getKey());

        if ($row === null || $row->provider_domain_id === null) {
            return $this->fail(self::REASON_RECORDS_MISSING) + ['records' => []];
        }

        if (! SendGridClient::configured()) {
            return $this->fail(self::REASON_NOT_CONFIGURED) + ['records' => $row->dnsRecords()];
        }

        // OUR read first: it names the record that is missing, and it costs the
        // provider nothing.
        $resolved = $this->resolveRecords($row->dnsRecords());
        $allResolve = $resolved !== [] && ! in_array(false, array_column($resolved, 'resolved'), true);

        $verdict = $this->client->validateDomain((int) $row->provider_domain_id);

        if ($verdict === null) {
            $row->forceFill([
                'last_checked_at' => now(),
                'failure_reason' => $this->providerFailureReason(),
            ])->save();

            return $this->fail($this->providerFailureReason()) + ['records' => $resolved];
        }

        // The provider's per-record verdicts replace ours where it gave them —
        // it is the party that decides, and it sees its own key rotations.
        if ($verdict['records'] !== []) {
            $row->records = $verdict['records'];
            $resolved = $this->resolveRecords($row->dnsRecords());
        }

        if ($verdict['valid']) {
            $row->forceFill([
                'status' => ShopSenderDomain::STATUS_VERIFIED,
                'failure_reason' => null,
                'last_checked_at' => now(),
                'verified_at' => $row->verified_at ?? now(),
            ])->save();

            return $this->ok() + ['records' => $resolved];
        }

        $row->forceFill([
            'status' => ShopSenderDomain::STATUS_FAILED,
            'failure_reason' => self::REASON_RECORDS_MISSING,
            'last_checked_at' => now(),
            // NOT cleared: a domain that verified once and is being re-checked
            // keeps its history; `status` is what the mail path reads.
        ])->save();

        return $this->fail(self::REASON_RECORDS_MISSING) + ['records' => $resolved];
    }

    /**
     * Stop sending as this domain, and give it back to the provider.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function remove(Shop $shop): array
    {
        $row = ShopSenderDomain::forShop((int) $shop->getKey());
        if ($row === null) {
            return $this->ok();
        }

        if ($row->provider_domain_id !== null && SendGridClient::configured()) {
            $this->client->removeDomain((int) $row->provider_domain_id);
        }

        Tenant::run($shop, static fn () => $row->delete());

        return $this->ok();
    }

    /**
     * Each expected record, with whether it RESOLVES right now. The reading
     * itself is DnsRecords', shared with the platform's own domain so the two
     * flows cannot disagree about what "live" means.
     *
     * @param  list<array{host: string, type: string, value: string, valid: bool}>  $records
     * @return list<array{host: string, type: string, value: string, valid: bool, resolved: bool}>
     */
    public function resolveRecords(array $records): array
    {
        return DnsRecords::resolve($records);
    }

    /**
     * "https://Shop.CO.IL/" → "shop.co.il", and null for anything that is not a
     * hostname. Merchants paste URLs; a stored "https://x/" would be requested
     * from the provider verbatim and refused.
     */
    public static function normaliseDomain(string $raw): ?string
    {
        $value = mb_strtolower(trim($raw));

        if ($value === '') {
            return null;
        }

        // Strip a scheme, any path, and a trailing dot.
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = (string) preg_replace('#[/?].*$#', '', $value);
        $value = trim($value, '.');

        // A leading www. is the site, not the mail domain; the merchant means
        // the registrable domain under it.
        $value = (string) preg_replace('/^www\./', '', $value);

        if (mb_strlen($value) > ShopSenderDomain::MAX_DOMAIN) {
            return null;
        }

        return preg_match(self::DOMAIN_PATTERN, $value) === 1 ? $value : null;
    }

    /** @return array{ok: true, reason: null} */
    private function ok(): array
    {
        return ['ok' => true, 'reason' => null];
    }

    /**
     * Why the provider call came back empty — refused, or unreachable?
     *
     * The difference is the whole answer to the owner. A refused key is
     * something only they can fix, and telling them "nothing is broken, try
     * again in a moment" sends them to wait for a network that is perfectly
     * healthy. Everything used to collapse into that one sentence.
     */
    private function providerFailureReason(): string
    {
        return $this->client->lastCallWasUnauthorized()
            ? self::REASON_PROVIDER_UNAUTHORIZED
            : self::REASON_PROVIDER_UNREACHABLE;
    }

    /** The provider's own words about the last failure, when it gave any. */
    public function providerMessage(): string
    {
        return trim((string) ($this->client->lastError()['message'] ?? ''));
    }

    /** @return array{ok: false, reason: string} */
    private function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
