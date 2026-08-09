<?php

namespace Tests\Feature\Account;

use Tests\TestCase;

/**
 * The personal area's LAYOUT contract.
 *
 * The area shipped rendering into whatever column the theme handed it, stacked
 * as one endless list of cards — on a normal WooCommerce theme that is a ~600px
 * strip meant for prose, and it read as squeezed no matter how good the cards
 * were. It also left WooCommerce's own navigation drawn in the theme's default
 * style right beside it, so the screen looked like two products.
 *
 * These pin the three halves of the fix: the renderer's column split, the
 * stylesheet's shell + navigation, and the plugin's takeover of the page. Every
 * one of them is a contract BETWEEN files, which is exactly the kind that rots
 * silently — a rail nobody styles, a token nobody sets, a template nobody serves.
 */
final class AccountLayoutTest extends TestCase
{
    // === CONSTANTS ===
    private const PLUGIN_CSS = 'plugins/lets-payplus-woocommerce/assets/css/lets-account.css';
    private const PLUGIN_JS = 'plugins/lets-payplus-woocommerce/assets/js/lets-account.js';
    private const SAAS_CSS = 'public/account/lets-account.css';
    private const SAAS_JS = 'public/account/lets-account.js';
    private const PLUGIN_PHP = 'plugins/lets-payplus-woocommerce/includes/class-lets-account.php';
    private const SHELL_TEMPLATE = 'plugins/lets-payplus-woocommerce/templates/myaccount/my-account.php';
    private const DASHBOARD_TEMPLATE = 'plugins/lets-payplus-woocommerce/templates/myaccount/dashboard.php';
    private const SHELL_JS = 'plugins/lets-payplus-woocommerce/assets/js/lets-account-shell.js';

    public function test_the_two_copies_of_the_area_are_byte_identical(): void
    {
        // The storefront loads the plugin's copy; the admin preview loads the
        // SaaS's. They must be the same file or the preview lies.
        $this->assertSame(
            md5_file(base_path(self::PLUGIN_CSS)),
            md5_file(base_path(self::SAAS_CSS)),
            'lets-account.css has drifted between the plugin and the SaaS',
        );
        $this->assertSame(
            md5_file(base_path(self::PLUGIN_JS)),
            md5_file(base_path(self::SAAS_JS)),
            'lets-account.js has drifted between the plugin and the SaaS',
        );
    }

    public function test_the_renderer_splits_the_page_into_a_hero_and_two_columns(): void
    {
        $js = (string) file_get_contents(base_path(self::PLUGIN_JS));
        $css = (string) file_get_contents(base_path(self::PLUGIN_CSS));

        // The rail exists in the renderer for more than banners: the short,
        // glanceable sections move out of the main column into it.
        $this->assertStringContainsString('RAIL_SECTIONS', $js);
        $this->assertStringContainsString("'loyalty'", $js);
        $this->assertStringContainsString('has-rail', $js);

        // …and the stylesheet actually makes it a second column.
        $this->assertMatchesRegularExpression(
            '/@media\s*\(min-width:[^)]+\)\s*\{\s*\.lets-acct__grid\.has-rail\s*\{[^}]*grid-template-columns/s',
            $css,
            'the rail must become a column behind a min-width, or a phone gets two cramped ones',
        );
    }

    public function test_the_hero_stats_only_show_numbers_the_server_sent(): void
    {
        $js = (string) file_get_contents(base_path(self::PLUGIN_JS));
        $css = (string) file_get_contents(base_path(self::PLUGIN_CSS));

        $this->assertStringContainsString('.la-stat', $css);
        $this->assertStringContainsString('renderStats', $js);

        // No arithmetic on money in the strip — it reads values, it does not
        // compute them. A total summed in the browser could disagree with the
        // cards below it, and money is never recalculated client-side.
        $stats = (string) preg_replace('/^.*function renderStats\(state\) \{(.*?)\n    \}.*$/s', '$1', $js);
        $this->assertNotSame('', $stats, 'renderStats() not found');
        $this->assertDoesNotMatchRegularExpression('/[+\-*\/]=|\breduce\(/', $stats);
    }

    public function test_the_plugin_renders_the_whole_page_not_a_widget_in_it(): void
    {
        $php = (string) file_get_contents(base_path(self::PLUGIN_PHP));

        // EVERY navigation callback is replaced by ours — WooCommerce's own and
        // a theme's alike, or the page renders two menus side by side.
        $this->assertStringContainsString("remove_all_actions('woocommerce_account_navigation')", $php);
        $this->assertStringContainsString("add_action('woocommerce_account_navigation', 'lets_payplus_account_navigation')", $php);

        // …but the hooks other plugins add their links through keep firing.
        $this->assertStringContainsString("do_action('woocommerce_before_account_navigation')", $php);
        $this->assertStringContainsString("do_action('woocommerce_after_account_navigation')", $php);

        // The shell and the bare dashboard are served as template overrides.
        $this->assertFileExists(base_path(self::SHELL_TEMPLATE));
        $this->assertFileExists(base_path(self::DASHBOARD_TEMPLATE));
        $this->assertStringContainsString('lets-shell', (string) file_get_contents(base_path(self::SHELL_TEMPLATE)));
        $this->assertStringContainsString(
            "do_action('woocommerce_account_dashboard')",
            (string) file_get_contents(base_path(self::DASHBOARD_TEMPLATE)),
        );
    }

    /**
     * The takeover is total, and opting out is a deliberate act.
     *
     * This used to be the opposite: a theme that shipped its own my-account.php
     * kept it, on the reasoning that the merchant had chosen it. In practice
     * almost every Israeli theme ships WooCommerce's own file with the header
     * swapped, so the reasoning was wrong in the common case — the merchant had
     * chosen nothing, and the area they installed the plugin FOR silently never
     * appeared. Ours wins now; a merchant who really did write their own account
     * page turns us off with a filter.
     */
    public function test_the_account_templates_are_ours_even_over_a_theme_override(): void
    {
        $php = (string) file_get_contents(base_path(self::PLUGIN_PHP));

        // No comparison against WooCommerce's default is left to yield on.
        $this->assertStringNotContainsString('wp_normalize_path($template) !== wp_normalize_path($default)', $php);

        // The one way out is a filter, defaulting to "ours".
        $this->assertMatchesRegularExpression(
            "/apply_filters\(\s*'lets_payplus_account_own_template',\s*true,/",
            $php,
        );
        $this->assertMatchesRegularExpression(
            '/\$ours\s*=\s*LETS_ACCOUNT_TEMPLATE_DIR\s*\.\s*\$template_name;\s*return file_exists\(\$ours\)/s',
            $php,
        );
    }

    /**
     * A theme that never goes through WooCommerce's template at all cannot be
     * caught in PHP, so the shell script builds the shell from whatever the page
     * did render — and only when our own shell is genuinely absent.
     */
    public function test_the_shell_is_rebuilt_client_side_when_php_never_got_the_chance(): void
    {
        $js = (string) file_get_contents(base_path(self::SHELL_JS));

        $this->assertStringContainsString('function adopt()', $js);
        $this->assertStringContainsString("shell.className = 'lets-shell'", $js);

        // It refuses on anything it does not recognise rather than half-adopting.
        $this->assertStringContainsString('nav.parentNode !== content.parentNode', $js);
    }

    public function test_the_shell_is_styled_and_can_reclaim_the_page_width(): void
    {
        $css = (string) file_get_contents(base_path(self::PLUGIN_CSS));
        $shellJs = (string) file_get_contents(base_path(self::SHELL_JS));

        // The shell owns the page grid: navigation beside content.
        $this->assertMatchesRegularExpression('/\.lets-shell\s*\{[^}]*display:\s*grid/s', $css);
        $this->assertStringContainsString('.la-nav', $css);

        // The width custom properties are WRITTEN by the shell script and READ
        // by the stylesheet. Either half alone does nothing at all.
        foreach (['--la-shell-w', '--la-shell-pull'] as $token) {
            $this->assertStringContainsString($token, $css, "the stylesheet must read {$token}");
            $this->assertStringContainsString($token, $shellJs, "the shell script must write {$token}");
        }

        // …and it declines rather than guesses when the move could break a theme.
        $this->assertStringContainsString('isConstrained', $shellJs);
        $this->assertStringContainsString('hasNeighbour', $shellJs);
    }

    public function test_woocommerces_own_screens_are_reskinned_with_the_same_tokens(): void
    {
        $css = (string) file_get_contents(base_path(self::PLUGIN_CSS));

        // Orders, addresses and account details keep WooCommerce's markup, so the
        // whole point is that these selectors exist and consume our tokens.
        foreach ([
            '.lets-shell table.shop_table',
            '.lets-shell .woocommerce-Address',
            '.lets-shell .form-row',
            '.lets-shell .woocommerce-pagination',
        ] as $selector) {
            $this->assertStringContainsString($selector, $css, "{$selector} is not re-skinned");
        }

        // The area imposes no typography of its own. A merchant CAN now ask for
        // one, so the law is no longer "never declare a font" — it is "never
        // declare one the merchant did not ask for": every font-family in the
        // file has to sit behind a data-font selector, and the default value of
        // that attribute, `theme`, must have no rule at all.
        // Comments explain the rule and therefore contain the words; only the
        // declarations are under test.
        $declarations = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        foreach (explode('}', $declarations) as $block) {
            if (! str_contains($block, 'font-family')) {
                continue;
            }

            $selector = substr($block, 0, (int) strrpos($block, '{'));

            $this->assertStringContainsString(
                'data-font',
                $selector,
                'a font-family declaration escaped the opt-in: '.trim($selector),
            );
            $this->assertStringNotContainsString(
                "data-font='theme'",
                $selector,
                'the default must inherit the shop’s type, not declare one',
            );
        }
    }
}
