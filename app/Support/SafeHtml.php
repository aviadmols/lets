<?php

namespace App\Support;

/**
 * Merchant-authored HTML, made safe to put on a shopper's page.
 *
 * The personal area's renderer has no innerHTML anywhere by policy, because the
 * model carries merchant copy and product titles and the markup lands inside the
 * merchant's own storefront. A custom HTML block is the one thing that cannot
 * obey that rule — HTML is the whole feature — so the rule moves here instead:
 * the string is scrubbed on the SERVER, once, before it is ever sent.
 *
 * "The merchant is trusted, it is their own shop" is only half true, and the
 * wrong half. A LETS operator can enter a shop; a compromised or careless admin
 * account would otherwise be able to run script in every one of that shop's
 * customers' browsers, on a page that shows their subscriptions and their card's
 * last four digits. So this is an ALLOW-list: anything not named is removed.
 *
 * Deliberately NOT a general-purpose HTML purifier. It exists for merchant
 * marketing blocks — text, links, images, lists, headings — and refuses the rest
 * rather than trying to be clever about it. Anything richer belongs in an iframe
 * with its own origin, which is a bigger decision than a settings field.
 */
final class SafeHtml
{
    // === CONSTANTS ===
    /**
     * Tags a merchant may use in a promotional block. No `script`, no `style`,
     * no `iframe`, no `form`, no `object` — each of those is a way to run code,
     * restyle the whole page, or collect a shopper's input under our layout.
     */
    public const ALLOWED_TAGS = [
        'a', 'p', 'br', 'hr', 'span', 'div', 'strong', 'b', 'em', 'i', 'u', 'small',
        'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'figure', 'figcaption',
    ];

    /** Attributes that survive. Everything else — including every `on*` — is dropped. */
    public const ALLOWED_ATTRIBUTES = ['href', 'src', 'alt', 'title', 'target', 'rel', 'class', 'width', 'height'];

    /** A URL scheme we will let a merchant point at. */
    public const ALLOWED_SCHEMES = ['https', 'mailto', 'tel'];

    /** Long enough for a real marketing block, short enough not to be a page. */
    public const MAX_LENGTH = 20000;

    /** Clean HTML, or null when nothing usable is left. */
    public static function clean(mixed $html): ?string
    {
        if (! is_string($html)) {
            return null;
        }

        $html = trim($html);

        if ($html === '') {
            return null;
        }

        $html = mb_substr($html, 0, self::MAX_LENGTH);

        // Whole elements whose CONTENT is dangerous, not just their tag: leaving
        // the body of a <script> behind as text would dump code onto the page.
        $html = (string) preg_replace(
            '#<(script|style|iframe|object|embed|form|noscript)\b[^>]*>.*?</\1>#is',
            '',
            $html,
        );
        // …and the unclosed variants, which strip_tags would otherwise unwrap.
        $html = (string) preg_replace('#<(script|style|iframe|object|embed|form|noscript)\b[^>]*/?>#i', '', $html);

        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = self::scrubAttributes($html);

        return trim($html) === '' ? null : trim($html);
    }

    /**
     * Drop every attribute that is not on the allow-list, and every URL whose
     * scheme is not. `onclick`, `onerror`, `style`, `srcset`, `formaction` and
     * the rest simply do not survive, so no list of dangerous names has to stay
     * complete for this to hold.
     */
    private static function scrubAttributes(string $html): string
    {
        return (string) preg_replace_callback(
            '#<([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>#',
            static function (array $m): string {
                $tag = strtolower($m[1]);
                $kept = [];
                $keptNames = [];

                preg_match_all(
                    '#([a-zA-Z-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#',
                    $m[2],
                    $attrs,
                    PREG_SET_ORDER,
                );

                foreach ($attrs as $attr) {
                    $name = strtolower($attr[1]);

                    // The value sits in whichever quoting group matched — but
                    // PREG_SET_ORDER DROPS trailing groups that did not
                    // participate, so a double-quoted attribute has no [3] and
                    // no [4] at all. Reading them positionally worked right up
                    // until somebody pasted an EMPTY one (`alt=""`, `class=""`):
                    // the value falls past [2] and lands on a key that does not
                    // exist. Every branch is coalesced, and an empty value stays
                    // an empty value rather than becoming a missing one.
                    $value = '';
                    foreach ([2, 3, 4] as $group) {
                        if (($attr[$group] ?? '') !== '') {
                            $value = $attr[$group];
                            break;
                        }
                    }

                    if (! in_array($name, self::ALLOWED_ATTRIBUTES, true)) {
                        continue;
                    }

                    if (in_array($name, ['href', 'src'], true) && ! self::urlIsAllowed($value)) {
                        continue;
                    }

                    $kept[] = $name.'="'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'"';
                    $keptNames[$name] = true;
                }

                // A link that opens a new tab without `rel` hands the opener to
                // the destination. The merchant should not have to remember.
                //
                // Only when they did not write one themselves: emitting a second
                // `rel` beside the merchant's own produces invalid markup, and a
                // browser reading the FIRST attribute would then silently drop the
                // protection this line exists to add.
                if ($tag === 'a' && $kept !== [] && ! isset($keptNames['rel'])) {
                    $kept[] = 'rel="noopener"';
                }

                return '<'.$tag.($kept === [] ? '' : ' '.implode(' ', $kept)).'>';
            },
            $html,
        );
    }

    /** Relative and anchor links pass; a scheme must be one we named. */
    private static function urlIsAllowed(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        // `javascript:` and `data:` hide behind whitespace, tabs and entities.
        $normalised = strtolower((string) preg_replace('/[\s\x00-\x1F]+/', '', $url));

        if (str_starts_with($normalised, 'javascript:') || str_starts_with($normalised, 'data:')) {
            return false;
        }

        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === '' ? false : in_array($scheme, self::ALLOWED_SCHEMES, true);
    }
}
