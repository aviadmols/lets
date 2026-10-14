<?php

namespace App\Services\PayPlus;

/**
 * WHICH method the shopper actually paid with on a PayPlus hosted page.
 *
 * One page can offer the card, PayPal, Bit… and the money's paperwork must name
 * the one that was used: a PayPal payment declared as a card payment misstates
 * the tax document. PayPlus names a non-card payment in
 * `transaction.alternative_method_name` (its own WooCommerce plugin reads the same
 * field — "Callback has no top-level `method`"), with `method` as the fallback.
 * The body shape differs by path (push: `transaction.*` / root; pull:
 * `data.transaction.*`), so both are read.
 *
 * The WooCommerce side speaks gateway ids, so a PayPal payment is reported as
 * WOO_GATEWAY_PAYPAL — the id of the plugin's own "PayPal" checkout option — and
 * GreenInvoicePaymentType maps it to the PayPal payment type.
 */
final class PayPlusChargeMethod
{
    // === CONSTANTS ===
    public const PAYPAL = 'paypal';

    /** The plugin's card checkout option (WooCommerce gateway id). */
    public const WOO_GATEWAY_CARD = 'lets_payplus';

    /** The plugin's PayPal checkout option (WooCommerce gateway id). */
    public const WOO_GATEWAY_PAYPAL = 'lets_payplus_paypal';

    /** Order meta the finalizer stamps, read back by the plugin's invoicing report. */
    public const ORDER_META = 'lets_payplus_charge_method';

    private const PATHS = [
        'data.transaction.alternative_method_name',
        'transaction.alternative_method_name',
        'data.alternative_method_name',
        'alternative_method_name',
        'data.transaction.method',
        'transaction.method',
        'data.method',
        'method',
    ];

    /**
     * The method named in a PayPlus body, lower-cased ('paypal', 'bit',
     * 'credit-card'…), or null when the body names none.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromBody(array $body): ?string
    {
        foreach (self::PATHS as $path) {
            $value = data_get($body, $path);
            if (is_string($value) && trim($value) !== '') {
                return strtolower(trim($value));
            }
        }

        return null;
    }

    /** @param array<string, mixed> $body */
    public static function isPayPal(array $body): bool
    {
        return self::fromBody($body) === self::PAYPAL;
    }

    /**
     * The WooCommerce gateway id this payment is declared under on a document.
     *
     * @param  array<string, mixed>  $body
     */
    public static function wooGatewayFor(array $body): string
    {
        return self::isPayPal($body) ? self::WOO_GATEWAY_PAYPAL : self::WOO_GATEWAY_CARD;
    }
}
