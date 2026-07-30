<?php

namespace App\Services\WooCommerce\Orders;

/**
 * The mark on every order this app creates in WooCommerce.
 *
 * WooCommerce has no order tags — tags are a PRODUCT taxonomy in core, and with
 * HPOS an order is not even a post to hang a taxonomy on. So the tag is order
 * meta, and the plugin turns that meta into a visible "LETS" column and filter on
 * the orders list. The merchant gets the same answer Shopify's tags give them:
 * which of these orders did the app make, and which kind is each.
 *
 * The kind vocabulary deliberately mirrors config('shopify.tags') so a gift is a
 * gift on both platforms and the two cannot drift into different words for the
 * same thing.
 */
final class WooOrderTags
{
    // === CONSTANTS ===
    /** Names what the order IS. Already read by the invoicing walls — do not rename. */
    public const META_ROLE = 'lets_order_role';

    /** The tag list, comma-separated. What the plugin's orders column renders. */
    public const META_TAGS = 'lets_tags';

    /** The umbrella mark: "this order was created by LETS". */
    public const UMBRELLA = 'LETS';

    /** Kind tags, mirroring the Shopify vocabulary. */
    public const KIND_INSTALLMENTS = 'installments';
    public const KIND_RECURRING = 'recurring';
    public const KIND_UPSELL = 'upsell';
    public const KIND_GIFT = 'gift';

    private const SEPARATOR = ', ';

    /**
     * The meta pairs to merge into a WC order payload.
     *
     * @param  string  $role  the existing lets_order_role value (main_order, gift_order…)
     * @return list<array{key: string, value: string}>
     */
    public static function meta(string $role, string ...$kinds): array
    {
        return [
            ['key' => self::META_ROLE, 'value' => $role],
            ['key' => self::META_TAGS, 'value' => self::line(...$kinds)],
        ];
    }

    /** Umbrella first, then each kind. */
    public static function line(string ...$kinds): string
    {
        $tags = array_values(array_unique(array_filter(
            array_merge([self::UMBRELLA], $kinds),
            static fn (string $t): bool => trim($t) !== '',
        )));

        return implode(self::SEPARATOR, $tags);
    }
}
