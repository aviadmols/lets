<?php

namespace App\Services\Shopify\Orders;

/**
 * The tag line for an order this app creates.
 *
 * Every such order carries the UMBRELLA tag ("LETS") plus whatever kind tag
 * applies. The two answer different questions and the merchant needs both: "which
 * orders in my store did the app make?" is one filter, "which of those are gifts?"
 * is another. Before this, only the second question had an answer.
 *
 * One place decides, so a new order type cannot quietly ship untagged — which is
 * exactly how an order shows up in a merchant's store looking like a mystery sale.
 */
final class ShopifyOrderTags
{
    // === CONSTANTS ===
    /** Shopify takes tags as one comma-separated string. */
    private const SEPARATOR = ', ';

    /** The config key holding the umbrella tag. */
    private const UMBRELLA = 'app';

    /**
     * The tag line: umbrella first, then each named kind from config('shopify.tags').
     *
     * Unknown or unconfigured keys are dropped rather than emitted blank — a stray
     * empty tag becomes a nameless tag in the merchant's Shopify filter list.
     */
    public static function line(string ...$kinds): string
    {
        return implode(self::SEPARATOR, self::all(...$kinds));
    }

    /**
     * The same tags as a list — for callers that merge with an order's existing
     * tags rather than replacing them.
     *
     * @return array<int, string>
     */
    public static function all(string ...$kinds): array
    {
        $configured = (array) config('shopify.tags', []);

        $tags = [self::umbrella()];
        foreach ($kinds as $kind) {
            $tags[] = trim((string) ($configured[$kind] ?? ''));
        }

        return array_values(array_unique(array_filter($tags, static fn (string $t): bool => $t !== '')));
    }

    /** The umbrella tag alone — what "created by this app" is called in the store. */
    public static function umbrella(): string
    {
        return trim((string) (config('shopify.tags.'.self::UMBRELLA) ?: 'LETS'));
    }
}
