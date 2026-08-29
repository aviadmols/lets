<?php

namespace App\Domain\Campaigns\Studio;

use App\Domain\Campaigns\Email\Models\EmailCampaign;

/**
 * The variables a studio document may speak — a thin façade over the
 * campaign placeholders that already exist, NOT a second registry.
 *
 * The internal syntax stays the flat `{token}` the whole mail engine
 * substitutes with strtr; the spec's piped syntax is deliberately not adopted
 * (it would demand a parser beside the one-function substitution law).
 * Defaults live where they always lived — in the vars bag the sender builds —
 * so a token here is a PROMISE the send can keep, never a new mechanism.
 */
final class VariableRegistry
{
    /** @return list<string> the bare token names, no braces */
    public static function tokens(): array
    {
        return EmailCampaign::PLACEHOLDERS;
    }

    /** @return list<string> as written into content: `{customer_name}` … */
    public static function wrapped(): array
    {
        return array_map(static fn (string $token): string => '{'.$token.'}', self::tokens());
    }

    /** The human label for the pickers (lang-resolved by callers). */
    public static function labelKey(string $token): string
    {
        return 'studio.variable.'.$token;
    }

    /**
     * `{token}`s used in a text that no send will ever substitute — the QA
     * warning's raw material. `{7*7}` and `{מבצע}` are NOT flagged: only a
     * thing that LOOKS like one of ours but is not (a typo like
     * `{custmer_name}`) is worth interrupting a merchant about.
     *
     * @return list<string>
     */
    public static function unknownTokensIn(string $text): array
    {
        preg_match_all('/\{([a-z0-9_]{2,40})\}/', $text, $matches);

        $unknown = [];
        foreach (array_unique($matches[1]) as $token) {
            if (! in_array($token, self::tokens(), true)) {
                $unknown[] = $token;
            }
        }

        return $unknown;
    }
}
