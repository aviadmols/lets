<?php

namespace Tests\Feature\WooCommerce;

use Tests\TestCase;

/**
 * Plugin 0.55.0 — the walls around who a storefront request may act as.
 *
 *   - The thank-you routes refuse an order whose key is missing, not only one
 *     whose key is wrong (the accept route charges the saved card behind it).
 *   - An SMS sign-in may open an existing account only when that account's OWN
 *     stored phone is the handset that answered.
 *   - The personal area asserts an email to LETS only once the address is proven.
 *
 * The pure helpers run verbatim from the plugin source (lifted out, as
 * PluginQueryStringTest does); the WordPress-bound wiring is pinned on the source.
 */
final class PluginSignInHardeningTest extends TestCase
{
    // === CONSTANTS ===
    private const THANKYOU = 'plugins/lets-payplus-woocommerce/includes/class-lets-thankyou.php';

    private const ACCOUNT = 'plugins/lets-payplus-woocommerce/includes/class-lets-account.php';

    private const NOTIFY = 'plugins/lets-payplus-woocommerce/includes/class-lets-notify.php';

    private const LOYALTY = 'plugins/lets-payplus-woocommerce/includes/class-lets-loyalty.php';

    private const PHONE_META = 'billing_phone';

    private const PHONE_INDEX = '_lets_phone';

    /** @var array<int, array<string, string>> user id → meta, read by the stub below */
    public static array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$meta = [];

        if (! defined('LETS_ACCOUNT_PHONE_META')) {
            define('LETS_ACCOUNT_PHONE_META', self::PHONE_META);
            define('LETS_ACCOUNT_PHONE_INDEX', self::PHONE_INDEX);
        }

        if (! function_exists('get_user_meta')) {
            eval('function get_user_meta($id, $key, $single = false) { return \\'.self::class.'::$meta[$id][$key] ?? ""; }');
        }

        $this->lift(self::THANKYOU, 'lets_payplus_order_key_matches', '\(\$order, \$order_key\)');
        $this->lift(self::ACCOUNT, 'lets_payplus_account_digits', '\(\$phone\)');
        $this->lift(self::ACCOUNT, 'lets_payplus_account_user_phone_matches', '\(\$user, \$digits\)');
    }

    public function test_a_missing_order_key_is_refused(): void
    {
        $order = $this->order('wc_order_abc123');

        $this->assertFalse(lets_payplus_order_key_matches($order, ''));
        $this->assertFalse(lets_payplus_order_key_matches($order, '   '));
        $this->assertFalse(lets_payplus_order_key_matches($order, null));
        $this->assertFalse(lets_payplus_order_key_matches($order, ['wc_order_abc123']));
    }

    public function test_a_wrong_key_or_no_order_is_refused_and_the_right_key_passes(): void
    {
        $order = $this->order('wc_order_abc123');

        $this->assertFalse(lets_payplus_order_key_matches($order, 'wc_order_zzz'));
        $this->assertFalse(lets_payplus_order_key_matches(false, 'wc_order_abc123'));
        $this->assertFalse(lets_payplus_order_key_matches($this->order(''), ''));
        $this->assertTrue(lets_payplus_order_key_matches($order, 'wc_order_abc123'));
    }

    public function test_the_thank_you_and_iframe_routes_use_the_fail_closed_check(): void
    {
        $thankyou = $this->source(self::THANKYOU);
        $notify = $this->source(self::NOTIFY);

        $this->assertStringNotContainsString("\$order_key !== ''", $thankyou);
        $this->assertStringNotContainsString("\$order_key !== ''", $notify);
        $this->assertStringNotContainsString("\$key !== ''", $thankyou);
        $this->assertStringContainsString('lets_payplus_order_key_matches($order, $order_key)', $notify);
    }

    public function test_an_sms_sign_in_matches_only_the_accounts_own_phone(): void
    {
        $user = (object) ['ID' => 7];
        self::$meta[7] = [self::PHONE_META => '050-123 4567'];

        $this->assertTrue(lets_payplus_account_user_phone_matches($user, lets_payplus_account_digits('0501234567')));
        $this->assertFalse(lets_payplus_account_user_phone_matches($user, lets_payplus_account_digits('0529999999')));
        $this->assertFalse(lets_payplus_account_user_phone_matches($user, ''));

        // No phone on the account at all: never a match.
        self::$meta[8] = [];
        $this->assertFalse(lets_payplus_account_user_phone_matches((object) ['ID' => 8], lets_payplus_account_digits('0501234567')));
    }

    public function test_the_sms_known_member_path_never_links_a_phone_onto_an_account_by_email(): void
    {
        $source = $this->source(self::ACCOUNT);
        $provision = $this->body($source, 'lets_payplus_account_provision_known_member');
        $byPhone = $this->body($source, 'lets_payplus_account_known_by_phone');

        // The SMS branch leaves before anything is created or linked by email.
        $this->assertMatchesRegularExpression("/if \\('sms' === \\\$channel\\) \\{\\s*return lets_payplus_account_known_by_phone/", $provision);
        $this->assertStringNotContainsString('LETS_ACCOUNT_PHONE_INDEX, $phone', $provision);

        $this->assertStringContainsString('lets_payplus_account_user_phone_matches($existing, $phone)', $byPhone);
        $this->assertStringContainsString("'use_email'", $byPhone);
        $this->assertStringContainsString('lets_payplus_account_is_privileged($existing)', $byPhone);
        $this->assertStringNotContainsString('create_customer', $byPhone);
    }

    public function test_the_area_asserts_only_a_proven_email_and_sends_are_capped_per_visitor(): void
    {
        $account = $this->source(self::ACCOUNT);

        $this->assertStringContainsString('lets_payplus_account_asserted_email($user)', $this->body($account, 'lets_payplus_account_fetch'));
        $this->assertStringContainsString('lets_payplus_account_asserted_email($user)', $this->body($account, 'lets_payplus_account_rest_act'));
        $this->assertStringContainsString('lets_payplus_account_asserted_email($user)', $this->source(self::LOYALTY));
        $this->assertStringContainsString('lets_payplus_account_email_is_proven($user)', $this->body($account, 'lets_payplus_account_claim_guest_orders'));
        $this->assertStringContainsString('lets_payplus_account_spend_send_budget()', $this->body($account, 'lets_payplus_account_rest_code_request'));
    }

    private function order(string $key): object
    {
        return new class($key)
        {
            public function __construct(private string $key) {}

            public function get_order_key(): string
            {
                return $this->key;
            }
        };
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    private function body(string $source, string $function): string
    {
        preg_match('/function '.preg_quote($function, '/').'\(.*?\n\}/s', $source, $m);
        $this->assertNotEmpty($m, $function.'() not found in the plugin');

        return $m[0];
    }

    private function lift(string $path, string $function, string $signature): void
    {
        if (function_exists($function)) {
            return;
        }

        preg_match('/function '.$function.$signature.'\s*\{.*?\n\}/s', $this->source($path), $m);
        $this->assertNotEmpty($m, $function.'() not found in the plugin');
        eval($m[0]); // phpcs:ignore -- the function under test, verbatim from the plugin
    }
}
