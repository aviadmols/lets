<?php

namespace App\Domain\Mail;

/**
 * "Is this CNAME actually published yet?" — asked of the real DNS, from here.
 *
 * The provider's verdict is the one that counts (it is the party that will
 * refuse to sign), but it answers with a single yes/no and it caches. Resolving
 * each record ourselves turns "not verified" into "this host does not resolve",
 * which is the difference between a merchant fixing one line in their DNS panel
 * and a merchant guessing.
 *
 * A FAILURE READS AS "NOT YET". A record added a minute ago genuinely is not
 * visible, and a resolver that is down tells us nothing about the merchant's
 * DNS — so both answer false, and the screen says "missing" rather than
 * inventing an error the merchant cannot act on.
 *
 * Shared by the shop-side flow (SenderDomains) and the platform's own domain,
 * so the two can never disagree about what "live" means.
 */
final class DnsRecords
{
    /**
     * Each expected record, with whether it resolves right now.
     *
     * @param  list<array{host: string, type: string, value: string, valid: bool}>  $records
     * @return list<array{host: string, type: string, value: string, valid: bool, resolved: bool}>
     */
    public static function resolve(array $records): array
    {
        $out = [];

        foreach ($records as $record) {
            $out[] = $record + ['resolved' => self::cnameResolves($record['host'], $record['value'])];
        }

        return $out;
    }

    /** Does this host publish this CNAME target? Never throws; unknown = false. */
    public static function cnameResolves(string $host, string $expected): bool
    {
        if (! function_exists('dns_get_record')) {
            return false;
        }

        try {
            $answers = @dns_get_record($host, DNS_CNAME);
        } catch (\Throwable) {
            return false;
        }

        if (! is_array($answers)) {
            return false;
        }

        $expected = mb_strtolower(rtrim($expected, '.'));

        foreach ($answers as $answer) {
            $target = mb_strtolower(rtrim((string) ($answer['target'] ?? ''), '.'));
            if ($target !== '' && $target === $expected) {
                return true;
            }
        }

        return false;
    }
}
