<?php

namespace App\Domain\Bulk;

use RuntimeException;

/**
 * A bulk edit that must not be allowed to start.
 *
 * Thrown by an operation's normalise() when the parameters cannot produce a safe
 * change — an unreadable date, a cadence unit nobody bills on, a shift of zero.
 * It carries a TRANSLATION KEY rather than a sentence, because the only place it
 * is ever shown is the merchant's screen, in their language.
 *
 * It is deliberately a refusal BEFORE the run row exists. A bulk edit that has
 * been accepted must be answerable afterwards, and "accepted, then failed on its
 * first chunk because the date was nonsense" is a worse receipt than never having
 * started.
 */
final class InvalidBulkEdit extends RuntimeException
{
    // === CONSTANTS ===
    /** Fallback key when an operation does not name a more specific reason. */
    public const DEFAULT_KEY = 'subscriptions.bulk.error.invalid_params';

    public function __construct(public readonly string $translationKey = self::DEFAULT_KEY)
    {
        parent::__construct($translationKey);
    }

    /** The merchant-facing reason, translated. */
    public function reason(): string
    {
        return __($this->translationKey);
    }
}
