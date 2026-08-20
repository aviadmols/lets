<?php

namespace App\Domain\Account\Offers;

/**
 * The button vocabulary a merchant may write into an offer's custom HTML, and
 * the one place that decides which target each token means.
 *
 * FOUR WAYS TO ADDRESS A TARGET, in resolution order:
 *
 *   {{button}}            the FIRST target. The whole vocabulary for a
 *                         single-target offer, and the one that keeps every
 *                         block written before targets became a list working.
 *   {{button_<slug>}}     the merchant's own token_key. Readable, and stable
 *                         across every edit — the one to teach.
 *   {{button_<product>}}  the target's external product id ({{button_2675}}).
 *                         What a merchant reaches for when they think in
 *                         catalog ids rather than in slugs.
 *   {{button_2}}          the 1-based position. Always available, and always
 *                         the most fragile: reordering the targets moves it.
 *
 * The order matters and is not alphabetical convenience: a merchant who names a
 * target `2` has said something more deliberate than the position of the target
 * that happens to be second, so an exact slug always wins, then an exact product
 * id, then the ordinal.
 *
 * WHAT A TOKEN BECOMES is a SENTINEL, never a `<button>`. SafeHtml's allow-list
 * has no button tag, and it should not: the renderer swaps this span for a
 * control IT wired, carrying the target key in a data attribute so the click
 * posts back the right one. A merchant decides where a button goes; they can
 * never decide what it does.
 */
final class OfferButtonTokens
{
    // === CONSTANTS ===
    /**
     * `{{button}}` or `{{button_<key>}}`. The key charset is deliberately wider
     * than a token_key's (which is `[a-z0-9_]`): a product id may carry a dash or
     * upper case, and a token nobody can resolve is reported to the merchant
     * rather than silently ignored as "not a token at all".
     */
    public const PATTERN = '/\{\{button(?:_([A-Za-z0-9_\-]{1,64}))?\}\}/';

    /** The bare `{{button}}` — the first target. */
    public const TOKEN_BUTTON = '{{button}}';

    /** What every matched token becomes on the way out. */
    public const SLOT_FORMAT = '<span class="la-offer__slot" data-target="%s"></span>';

    /** The class the renderer looks for. Named here so one file owns the contract. */
    public const SLOT_CLASS = 'la-offer__slot';

    /**
     * Every button token in this block, deduplicated, in the order they appear.
     * The `key` is '' for the bare `{{button}}`.
     *
     * @return list<array{token: string, key: string}>
     */
    public static function tokensIn(?string $html): array
    {
        if (! is_string($html) || $html === '') {
            return [];
        }

        if (preg_match_all(self::PATTERN, $html, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($matches as $match) {
            $token = (string) $match[0];
            if (isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;

            $out[] = ['token' => $token, 'key' => (string) ($match[1] ?? '')];
        }

        return $out;
    }

    /**
     * Which target does this token key mean? Null when nothing answers to it —
     * which the admin form reports as an error rather than rendering a promotion
     * with a hole where a button should be.
     *
     * @param  list<array{token_key: ?string, external_product_id: ?string, position: int, stable_key: string}>  $targets
     * @return array{token_key: ?string, external_product_id: ?string, position: int, stable_key: string}|null
     */
    public static function resolve(string $key, array $targets): ?array
    {
        if ($targets === []) {
            return null;
        }

        // The bare token is the first target — the first in POSITION order, which
        // is the order the caller hands them over in.
        if ($key === '') {
            return $targets[0];
        }

        $lower = mb_strtolower(trim($key));

        foreach ($targets as $target) {
            if ($target['token_key'] !== null && $target['token_key'] === $lower) {
                return $target;
            }
        }

        foreach ($targets as $target) {
            if ($target['external_product_id'] !== null && $target['external_product_id'] === trim($key)) {
                return $target;
            }
        }

        if (ctype_digit($key)) {
            foreach ($targets as $target) {
                if ($target['position'] === (int) $key) {
                    return $target;
                }
            }
        }

        return null;
    }

    /**
     * The strtr substitution map for this block: every token that resolves maps to
     * its target's slot.
     *
     * A token that does NOT resolve maps to the empty string. The admin form
     * refuses to save such a block in the first place (validateCustomHtml), so
     * reaching this state means the target list changed underneath a saved block
     * — and printing a raw `{{button_gone}}` on a signed-in shopper's page is
     * worse than printing nothing.
     *
     * @param  list<array{token_key: ?string, external_product_id: ?string, position: int, stable_key: string}>  $targets
     * @return array<string, string>
     */
    public static function slots(?string $html, array $targets): array
    {
        $map = [];

        foreach (self::tokensIn($html) as $token) {
            $resolved = self::resolve($token['key'], $targets);
            $map[$token['token']] = $resolved !== null ? self::slot($resolved['stable_key']) : '';
        }

        return $map;
    }

    /** The sentinel for one target key. The key is escaped — it reaches an attribute. */
    public static function slot(string $stableKey): string
    {
        return sprintf(self::SLOT_FORMAT, htmlspecialchars($stableKey, ENT_QUOTES, 'UTF-8'));
    }
}
