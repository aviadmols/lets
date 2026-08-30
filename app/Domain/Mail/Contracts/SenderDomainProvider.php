<?php

namespace App\Domain\Mail\Contracts;

/**
 * What a sending provider must be able to do about DOMAINS.
 *
 * Not about sending — mail leaves over plain SMTP, and every provider speaks
 * that. This is the paperwork: ask the provider to authenticate a domain, read
 * back the records the owner must publish, ask them to look again once it is
 * published, and hand the domain back when a shop leaves.
 *
 * NOTHING HERE THROWS. A provider fault answers null (or false) and is
 * explained through lastError(); a settings screen must not 500 because a third
 * party is slow — and, just as importantly, must not tell an owner "nothing is
 * broken" when the provider actually refused their credentials.
 */
interface SenderDomainProvider
{
    /**
     * Ask the provider to authenticate a domain.
     *
     * @return array{id: string, records: list<array<string, mixed>>, subdomain: string}|null
     */
    public function authenticateDomain(string $domain, ?string $subdomain = null): ?array;

    /**
     * Ask the provider to CHECK the DNS now.
     *
     * The per-record verdicts are what make a failed check actionable: "not
     * verified" sends an owner hunting, "this one CNAME is missing" sends them
     * to the right line in their DNS.
     *
     * @return array{valid: bool, records: list<array<string, mixed>>}|null
     */
    public function validateDomain(string $domainId): ?array;

    /**
     * The domain as the provider holds it now — used to re-read records we lost.
     *
     * @return array{id: string, valid: bool, records: list<array<string, mixed>>, subdomain: string}|null
     */
    public function fetchDomain(string $domainId): ?array;

    /** Give the domain back. A shop that left keeps nothing on our account. */
    public function removeDomain(string $domainId): bool;

    /**
     * Why the last call came back empty.
     *
     * @return array{status: ?int, message: string}|null
     */
    public function lastError(): ?array;

    /** True when the provider REFUSED the credentials rather than not answering. */
    public function lastCallWasUnauthorized(): bool;
}
