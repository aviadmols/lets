<?php

namespace App\Support;

/**
 * ONE rule for what a typed Israeli phone number means.
 *
 * A shopper types `050-123 4567` when they ask for a code and `0501234567` when
 * they come back to type it in, and both are the same handset. Every part of the
 * sign-in path therefore has to agree on a single canonical form, or the system
 * disagrees with itself in the three places it hurts:
 *
 *   - the code is stored under one hash and looked up under another, so a correct
 *     code is answered with "that code is not right";
 *   - "asking for a new code retires the old one" stops holding, because the
 *     invalidation query looks for a hash the earlier row was never written under;
 *   - the per-destination throttle becomes per-FORMATTING, so one handset can be
 *     sent a multiple of the documented cap — on the merchant's SMS budget.
 *
 * So: canonicalise once, here, and let CustomerLoginCode::hashDestination and the
 * 019 sender both call it. The rule is deliberately forgiving. This number came off
 * an order the merchant already fulfilled; refusing it for punctuation would fail a
 * real customer to guard against nothing.
 */
final class PhoneNumber
{
    // === CONSTANTS ===
    /** An Israeli local mobile: 10 digits, `05` then eight more. */
    private const LOCAL_MOBILE = '/^05\d{8}$/';

    /** Outside that shape we still accept a plausible international number. */
    private const MIN_DIGITS = 9;

    private const MAX_DIGITS = 15;

    /**
     * The canonical form of a typed number, or null when it cannot be one.
     *
     * `+972-50-123 4567`, `00972501234567`, `972501234567` and `050 123 4567` all
     * fold to `0501234567`. Both country-code spellings are folded: a shopper who
     * dials out of a saved contact often carries the `00` prefix with them.
     */
    public static function canonical(string $phone): ?string
    {
        $digits = self::digits($phone);

        if (preg_match(self::LOCAL_MOBILE, $digits) === 1) {
            return $digits;
        }

        $length = strlen($digits);

        return $length >= self::MIN_DIGITS && $length <= self::MAX_DIGITS ? $digits : null;
    }

    /**
     * The folding step on its own: digits, country code resolved, no judgement
     * about whether the result is dialable.
     *
     * Exposed because the code table needs a STABLE key even for a number it
     * would refuse to dial — and that key has to be folded the same way the
     * WordPress plugin folds it (lets_payplus_account_digits). Falling back to
     * raw digits here would put the two sides back out of step for exactly the
     * inputs canonical() rejects, which is the divergence this class exists to
     * close.
     */
    public static function digits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00972')) {
            return '0'.substr($digits, 5);
        }

        if (str_starts_with($digits, '972')) {
            return '0'.substr($digits, 3);
        }

        return $digits;
    }
}
