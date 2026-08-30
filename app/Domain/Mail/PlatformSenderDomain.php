<?php

namespace App\Domain\Mail;

use App\Domain\Mail\Contracts\SenderDomainProvider;
use App\Models\PlatformMailSettings;

/**
 * The PLATFORM's own authenticated domain — the same conversation with the
 * provider a shop has, for the house's own address.
 *
 * It matters more than any single shop's. Every merchant who has not yet
 * verified a domain of their own sends as the platform's `from_address`, so an
 * address on a domain this account never authenticated is not one shop's
 * problem — it is every shop's mail refused at once. That is why this is a
 * screen with a state rather than a variable somebody set correctly one
 * afternoon.
 *
 * Deliberately NOT SenderDomains with a null shop: that class is tenant-shaped
 * all the way down (a row per shop, a global scope, a tenant to bind), and
 * bending it around a holder with no tenant would make the shop path harder to
 * read for the sake of one extra caller. What the two DO share is the part that
 * must not drift — the provider client and the DNS reading.
 *
 * Nothing here throws: a provider outage leaves the row where it was, with a
 * reason on it.
 */
final class PlatformSenderDomain
{
    public function __construct(private readonly ?SenderDomainProvider $client = null) {}

    /**
     * The provider the platform chose, resolved once and reused.
     *
     * An injected client wins — that is how a test hands in a fake without
     * the factory knowing anything about tests.
     */
    private function provider(): SenderDomainProvider
    {
        // MEMOISED, and that is load-bearing: the client records WHY its last
        // call failed, so asking the factory a second time would hand back a
        // fresh object with no memory of the refusal we are about to explain.
        return $this->resolved ??= $this->client ?? SenderDomainProviderFactory::current();
    }

    private ?SenderDomainProvider $resolved = null;

    /**
     * Ask the provider to authenticate the platform's domain.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function request(string $domain): array
    {
        $settings = PlatformMailSettings::current();

        if (! $settings->isConnected()) {
            return $this->fail(SenderDomains::REASON_NOT_CONFIGURED);
        }

        $domain = SenderDomains::normaliseDomain($domain);
        if ($domain === null) {
            return $this->fail(SenderDomains::REASON_INVALID_DOMAIN);
        }

        if ($settings->domain === $domain && $settings->provider_domain_id !== null) {
            return $this->ok();
        }

        $created = $this->provider()->authenticateDomain($domain, $settings->subdomain());
        if ($created === null) {
            return $this->fail(SenderDomains::REASON_PROVIDER_UNREACHABLE);
        }

        // The old id is released only AFTER the new one exists — a refused
        // request must not leave the platform with neither.
        if ($settings->provider_domain_id !== null) {
            $this->provider()->removeDomain((string) $settings->provider_domain_id);
        }

        $settings->forceFill([
            'domain' => $domain,
            'subdomain' => $created['subdomain'],
            'provider_domain_id' => $created['id'],
            'status' => PlatformMailSettings::STATUS_PENDING,
            'records' => $created['records'],
            'last_checked_at' => null,
            'verified_at' => null,
        ])->save();

        return $this->ok();
    }

    /**
     * Check the DNS. Our own resolver read names the missing record; the
     * provider's verdict is what decides.
     *
     * @return array{ok: bool, reason: ?string, records: list<array<string, mixed>>}
     */
    public function check(): array
    {
        $settings = PlatformMailSettings::current();

        if ($settings->provider_domain_id === null) {
            return $this->fail(SenderDomains::REASON_RECORDS_MISSING) + ['records' => []];
        }

        if (! $settings->isConnected()) {
            return $this->fail(SenderDomains::REASON_NOT_CONFIGURED) + ['records' => $settings->dnsRecords()];
        }

        $resolved = DnsRecords::resolve($settings->dnsRecords());
        $verdict = $this->provider()->validateDomain((string) $settings->provider_domain_id);

        if ($verdict === null) {
            $settings->forceFill(['last_checked_at' => now()])->save();

            return $this->fail(SenderDomains::REASON_PROVIDER_UNREACHABLE) + ['records' => $resolved];
        }

        if ($verdict['records'] !== []) {
            $settings->records = $verdict['records'];
            $resolved = DnsRecords::resolve($settings->dnsRecords());
        }

        $settings->forceFill([
            'status' => $verdict['valid']
                ? PlatformMailSettings::STATUS_VERIFIED
                : PlatformMailSettings::STATUS_FAILED,
            'last_checked_at' => now(),
            'verified_at' => $verdict['valid'] ? ($settings->verified_at ?? now()) : $settings->verified_at,
        ])->save();

        return ($verdict['valid'] ? $this->ok() : $this->fail(SenderDomains::REASON_RECORDS_MISSING))
            + ['records' => $resolved];
    }

    /** @return array{ok: true, reason: null} */
    private function ok(): array
    {
        return ['ok' => true, 'reason' => null];
    }

    /** @return array{ok: false, reason: string} */
    private function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
