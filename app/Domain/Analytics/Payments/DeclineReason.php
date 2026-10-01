<?php

namespace App\Domain\Analytics\Payments;

use App\Domain\Installments\ImportedTokenRecovery;

/**
 * A free-text decline → one of a handful of reason buckets, for the Payments
 * screens (data-map §3 "Failure reasons ◐").
 *
 * PayPlus answers `failure_code = 1` for EVERY card decline, so its Hebrew
 * `failure_message` is the only signal there is (memory: payplus decline codes
 * are undifferentiated). The wordings below are the ones seen on a live book
 * and already matched elsewhere — the stolen/dead-card families are read from
 * ImportedTokenRecovery itself so the two lists cannot drift. Shopify Payments
 * attempts carry an English error code (`insufficient_funds`,
 * `expired_payment_method`, `card_declined`…), matched by the same table.
 *
 * First match wins, in BUCKETS order: "stolen" outranks every other reading
 * (the same safe direction ImportedTokenRecovery takes). Anything unrecognised
 * is OTHER — never guessed into a named bucket.
 */
final class DeclineReason
{
    // === CONSTANTS ===
    public const STOLEN = 'stolen_lost';

    public const TOKEN_MISSING = 'token_missing';

    public const EXPIRED = 'expired';

    public const BLOCKED = 'blocked';

    public const INSUFFICIENT_FUNDS = 'insufficient_funds';

    public const CALL_ISSUER = 'call_issuer';

    public const REFUSED = 'refused';

    public const TECHNICAL = 'technical';

    public const OTHER = 'other';

    /** Bucket => substrings (case-insensitive), in match order. */
    public const BUCKETS = [
        self::STOLEN => ImportedTokenRecovery::STOLEN_CARD_DECLINES,
        self::TOKEN_MISSING => ['token-not-exist'],
        self::EXPIRED => ['אינו בתוקף', 'פג תוקף', 'expired'],
        self::BLOCKED => ['חסום', 'blocked'],
        self::INSUFFICIENT_FUNDS => ['כיסוי', 'insufficient'],
        self::CALL_ISSUER => ['התקשר', 'call_issuer', 'call issuer'],
        self::REFUSED => ['סירוב', 'לא אושרה', 'declined', 'refused', 'do_not_honor', 'do not honor'],
        self::TECHNICAL => ['timeout', 'timed out', 'connection', 'שגיאת', 'תקלה', 'processing_error'],
    ];

    /** Every bucket a screen may show, in legend order (OTHER last). */
    public const ALL = [
        self::INSUFFICIENT_FUNDS, self::BLOCKED, self::EXPIRED, self::STOLEN, self::REFUSED,
        self::CALL_ISSUER, self::TOKEN_MISSING, self::TECHNICAL, self::OTHER,
    ];

    /** Bucket of a decline message (and/or code). Empty → OTHER. */
    public static function of(?string $message, ?string $code = null): string
    {
        $text = mb_strtolower(trim((string) $message.' '.(string) $code));
        if ($text === '') {
            return self::OTHER;
        }

        foreach (self::BUCKETS as $bucket => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, mb_strtolower($needle))) {
                    return $bucket;
                }
            }
        }

        return self::OTHER;
    }

    public static function label(string $bucket): string
    {
        return __('analytics/payments_failures.reason.'.(in_array($bucket, self::ALL, true) ? $bucket : self::OTHER));
    }
}
