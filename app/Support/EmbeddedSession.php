<?php

namespace App\Support;

/**
 * "Is this admin request being rendered INSIDE somebody else's page?" — the one
 * place that question is answered for the WordPress (WooCommerce) embed.
 *
 * A WooCommerce merchant never signs in: they click LETS in wp-admin, the plugin
 * asks us for a one-shot URL (EmbedSessionController), and the iframe redeems it
 * (EmbedLoginController) into a PLAIN COOKIE SESSION. From then on the only trace
 * of "embedded" is the two session keys below — deliberately NOT the Shopify ones
 * (PersistEmbeddedContext::SESSION_EMBEDDED), because the Shopify path means App
 * Bridge + session-token bouncing and this path means neither.
 *
 * Reads are defensive: canAccess()/shouldRegisterNavigation() run in contexts that
 * may have no started session (console, a queued job that touches a screen class),
 * and a missing session must read as "not embedded" rather than throw.
 */
final class EmbeddedSession
{
    // === CONSTANTS ===
    /** Which host page we are rendered inside ('woocommerce'), if any. */
    public const SESSION_PLATFORM = 'embedded_platform';

    /** Where "back to the store admin" should send the merchant (wp-admin). */
    public const SESSION_RETURN_URL = 'embedded_return_url';

    public const PLATFORM_WOOCOMMERCE = 'woocommerce';

    /** The host platforms this app can be embedded in through a cookie session. */
    public const PLATFORMS = [self::PLATFORM_WOOCOMMERCE];

    /** The embedding platform for this request, or null when standalone. */
    public static function platform(): ?string
    {
        $platform = self::read(self::SESSION_PLATFORM);

        return in_array($platform, self::PLATFORMS, true) ? $platform : null;
    }

    /** Rendered inside wp-admin right now? The gate for the filtered menu. */
    public static function isWooCommerce(): bool
    {
        return self::platform() === self::PLATFORM_WOOCOMMERCE;
    }

    /** The wp-admin URL the merchant came from (never rendered as HTML unescaped). */
    public static function returnUrl(): ?string
    {
        return self::read(self::SESSION_RETURN_URL);
    }

    /** Session read that cannot explode outside an HTTP request. */
    private static function read(string $key): ?string
    {
        if (! app()->bound('session')) {
            return null;
        }

        try {
            $value = app('session')->get($key);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
