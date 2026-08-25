<?php

namespace App\Domain\Campaigns\Email;

use App\Domain\Campaigns\Email\Models\EmailCampaign;

/**
 * Tidies a merchant-authored campaign body on the way IN.
 *
 * ONE real job: a rich-text editor (and every browser that touches an href)
 * percent-encodes the braces of a placeholder written into a LINK, so
 * `href="{account_login_url}"` comes back as `href="%7Baccount_login_url%7D"`
 * and strtr — which matches bytes — never substitutes it. The merchant's button
 * then points at a literal path with a percent-encoded name, and the whole
 * passwordless link silently does nothing. Decoding the known tokens back is
 * the difference between a working campaign and a dead one.
 *
 * Deliberately NOT a sanitiser. The body is never compiled (strtr, not Blade)
 * and is rendered only inside a sandboxed iframe in the admin and in the
 * recipient's own mail client, which does its own scrubbing.
 */
final class CampaignBodyNormalizer
{
    // === CONSTANTS ===
    /** The encoded spellings a token can come back as. */
    private const ENCODED_OPEN = ['%7B', '%7b'];

    private const ENCODED_CLOSE = ['%7D', '%7d'];

    /** Restore every known placeholder and trim to the column's ceiling. */
    public static function clean(mixed $body): string
    {
        $body = is_string($body) ? $body : '';

        $map = [];
        foreach (EmailCampaign::PLACEHOLDERS as $token) {
            foreach (self::ENCODED_OPEN as $open) {
                foreach (self::ENCODED_CLOSE as $close) {
                    $map[$open.$token.$close] = '{'.$token.'}';
                }
            }
        }

        $body = strtr($body, $map);

        return mb_substr(trim($body), 0, EmailCampaign::MAX_BODY);
    }
}
