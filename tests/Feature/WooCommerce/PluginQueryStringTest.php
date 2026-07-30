<?php

namespace Tests\Feature\WooCommerce;

use Tests\TestCase;

/**
 * The plugin helper that took a live store down.
 *
 * `lets_payplus_signed_get($path, $query)` documented $query as a string, and two
 * of its three callers pass `array()` for "no query". `ltrim(array(), '?')` was a
 * warning on PHP 7 and is a TypeError on PHP 8 — so the day the store moved to PHP
 * 8.4, every read of the invoicing settings became a fatal. Not a failed read: a
 * fatal, inside a WooCommerce hook, which takes down the whole request. The
 * merchant's thank-you page 500'd, every order save 500'd, and gift orders came
 * back 500 after WooCommerce had already created them.
 *
 * These run the plugin's real function, loaded from the plugin source, so the
 * contract is pinned where it broke rather than in a copy of it.
 */
final class PluginQueryStringTest extends TestCase
{
    // === CONSTANTS ===
    private const WIDGET = 'plugins/lets-payplus-woocommerce/includes/class-lets-product-widget.php';
    private const INVOICING = 'plugins/lets-payplus-woocommerce/includes/class-lets-invoicing.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('lets_payplus_query_string')) {
            // The plugin file is WordPress code; lift out just the helper rather
            // than booting WordPress to test one pure function.
            $source = (string) file_get_contents(base_path(self::WIDGET));
            preg_match('/function lets_payplus_query_string\(\$query\)\s*\{.*?\n\}/s', $source, $m);
            $this->assertNotEmpty($m, 'lets_payplus_query_string() not found in the plugin');
            eval($m[0]); // phpcs:ignore -- the function under test, verbatim from the plugin
        }
    }

    public function test_an_empty_array_is_no_query_not_a_fatal(): void
    {
        // Exactly what class-lets-invoicing.php and class-lets-page-settings.php pass.
        $this->assertSame('', lets_payplus_query_string([]));
    }

    public function test_an_array_of_parameters_becomes_a_query_string(): void
    {
        $this->assertSame('order_id=7&key=abc', lets_payplus_query_string(['order_id' => 7, 'key' => 'abc']));
    }

    public function test_a_prebuilt_string_still_works(): void
    {
        // What the thank-you upsell passes (http_build_query output).
        $this->assertSame('order_id=7&key=abc', lets_payplus_query_string('order_id=7&key=abc'));
        $this->assertSame('order_id=7', lets_payplus_query_string('?order_id=7'));
    }

    public function test_nothing_at_all_is_no_query(): void
    {
        $this->assertSame('', lets_payplus_query_string(null));
        $this->assertSame('', lets_payplus_query_string(false));
    }

    public function test_reading_the_invoicing_settings_can_never_fatal_a_page(): void
    {
        $source = (string) file_get_contents(base_path(self::INVOICING));

        // It runs inside woocommerce_order_status_changed and on the customer's
        // order-received page. A fatal there is not a failed read — it is a broken
        // store, which is what happened.
        $this->assertMatchesRegularExpression(
            '/function lets_payplus_invoicing_settings\(\)\s*\{\s*(\/\*.*?\*\/\s*)?try\s*\{/s',
            $source,
            'lets_payplus_invoicing_settings() must not be able to throw into a WordPress hook',
        );
        $this->assertStringContainsString('catch (\Throwable $e)', $source);
    }
}
