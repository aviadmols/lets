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

    /**
     * The full-width strip is a PLACEMENT, not a second component: it draws the
     * same banner markup with a modifier class, above the grid, and it tolerates
     * a payload from a LETS that predates the key.
     */
    public function test_top_banners_are_drawn_above_the_two_columns(): void
    {
        $js = (string) file_get_contents(base_path(self::PLUGIN_JS));
        $css = (string) file_get_contents(base_path(self::PLUGIN_CSS));

        $this->assertStringContainsString('model.top_banners', $js);
        $this->assertStringContainsString("renderBanner(banner, 'la-banner--top')", $js);

        // The strip is appended before the grid is built, or it is not a top banner.
        $this->assertMatchesRegularExpression(
            "/la-topbanners.*?append\(mount, strip\);.*?var grid = el\('div', 'lets-acct__grid'\)/s",
            $js,
        );

        // An old payload has no key at all: the guard must leave the page as it was.
        $this->assertStringContainsString(
            'Array.isArray(model.top_banners) ? model.top_banners : []',
            $js,
        );

        foreach (['.la-topbanners', '.la-banner--top'] as $selector) {
            $this->assertStringContainsString($selector, $css, "{$selector} is not styled");
        }
    }

    /**
     * Offers are the only thing on this page that can move money, and they are
     * drawn in three places at once — a strip above the plans, the rail, and
     * under the single plan an offer would replace. All three are ONE function
     * with a modifier class, for the same reason the banners are: three copies
     * of a card that charges a card is three chances to disagree about what it
     * says before the shopper confirms it.
     */
    public function test_offers_are_drawn_in_all_three_placements(): void
    {
        $js = (string) file_get_contents(base_path(self::PLUGIN_JS));
        $css = (string) file_get_contents(base_path(self::PLUGIN_CSS));

        $this->assertStringContainsString('function renderOffer(', $js);
        $this->assertStringContainsString("perform(state, 'accept_offer'", $js);

        // Every placement, and every one of them tolerant of a payload from a
        // LETS that predates offers — a missing key draws nothing, it does not
        // throw and take the whole area down with it.
        foreach (['model.offers', 'model.rail_offers', 'sub.offers'] as $key) {
            $this->assertStringContainsString(
                'Array.isArray('.$key.') ? '.$key.' : []',
                $js,
                "{$key} must be guarded, or an old payload breaks the page",
            );
        }

        foreach (['la-offers--top', 'la-offers--rail', 'la-offers--plan'] as $modifier) {
            $this->assertStringContainsString($modifier, $js, "{$modifier} is never rendered");
            $this->assertStringContainsString('.'.$modifier, $css, "{$modifier} is not styled");
        }

        // The accept button is ours: the merchant's {{button}} is only a slot.
        $this->assertStringContainsString('la-offer__slot', $js);
        $this->assertStringContainsString('.la-offer__slot', $css);

        // A charge on a card the shopper cannot see is confirmed first, with the
        // server's own sentence — never a string composed in the browser. The
        // sentence belongs to the TARGET: on a card offering three things,
        // confirming the wrong one is worse than confirming nothing.
        $this->assertStringContainsString('window.confirm(target.disclosure)', $js);
    }

    /**
     * One card, several things to say yes to.
     *
     * An offer used to be one product and one button. It is a LIST now — switch
     * the plan, add the refill, buy the accessory once on the card already on
     * file — and each row carries its own price, its own dates and its own
     * button. The click therefore has to name WHICH: an offer id alone no longer
     * says what was accepted, and the amount travelling beside it would then be
     * evidence for a target nobody named.
     */
    public function test_one_offer_can_carry_several_targets(): void
    {
        $js = (string) file_get_contents(base_path(self::PLUGIN_JS));
        $css = (string) file_get_contents(base_path(self::PLUGIN_CSS));

        // The list, in the renderer and in the stylesheet. Either half alone is
        // a row of unstyled paragraphs or a rule nothing ever matches.
        $this->assertStringContainsString('la-offer__targets', $js);
        $this->assertStringContainsString('.la-offer__targets', $css);
        foreach (['.la-offer__target', '.la-offer__target-price', '.la-offer__target-note'] as $selector) {
            $this->assertStringContainsString($selector, $css, "{$selector} is not styled");
        }

        // The verb names the target. This is the whole contract with the SaaS.
        $this->assertStringContainsString('target: target.key', $js);

        // The merchant's own markup places each button by key; a slot is matched
        // by the target it names, never by its position in the string.
        $this->assertStringContainsString('data-target', $js);
        $this->assertStringContainsString('slot.getAttribute(OFFER_SLOT_KEY)', $js);
        $this->assertStringContainsString('slot.parentNode.removeChild(slot)', $js);

        // A payload with no targets is not a card with nothing to click — it is
        // not a card at all.
        $this->assertStringContainsString(
            'Array.isArray(offer.targets) ? byIndex(offer.targets) : []',
            $js,
        );
        $this->assertMatchesRegularExpression(
            '/if \(!targets\.length\) \{ return null; \}/',
            $js,
            'an offer with no targets must render nothing at all',
        );

        // …and the three placements must survive that null rather than throw on
        // appendChild(null) and take the whole area down.
        foreach (['offerStrip', 'railStrip', 'box'] as $strip) {
            $this->assertStringContainsString(
                'append('.$strip.', renderOffer(state, offer,',
                $js,
                "{$strip} must use append(), which skips a null card",
            );
        }

        // Copy the catalog has not learned yet degrades to the accept verb; it
        // never prints "undefined" beside a price.
        $this->assertStringContainsString('m.copy.offer_buy_now', $js);
        $this->assertStringContainsString('m.copy.offer_one_time', $js);
        $this->assertStringContainsString('m.copy.offer_add_to_next', $js);
        $this->assertStringContainsString(
            'target.button_text || fallback || m.copy.offer_accept',
            $js,
        );

        // The renderer's version is the plugin's compatibility handle: a payload
        // shape this wide has to be visible to the page that mounts it. Bumped
        // to 7 when the renderer learned `nonceHeader` — the SaaS-hosted area
        // sends Laravel's CSRF token, WordPress keeps its X-WP-Nonce default.
        $this->assertStringContainsString('version: 7', $js);
    }

    /**
     * A subscription that is OVER opens FOLDED, as a real <details>/<summary> —
     * not a scripted toggle. Keyboard, screen readers and find-in-page all work
     * without us, and the server decides WHICH (`sub.collapsed`), so the
     * merchant's preview and the live page cannot disagree about it.
     */
    public function test_an_ended_subscription_folds_as_a_native_disclosure(): void
    {
        $js = (string) file_get_contents(base_path(self::PLUGIN_JS));
        $css = (string) file_get_contents(base_path(self::PLUGIN_CSS));

        // The server's flag, and the two elements it swaps in.
        $this->assertStringContainsString('sub.collapsed', $js);
        $this->assertStringContainsString("el(collapsed ? 'details' : 'article'", $js);
        $this->assertStringContainsString("el(collapsed ? 'summary' : 'div', 'la-sub__head')", $js);

        // A payload from a LETS that predates the flag renders exactly as before:
        // `!!undefined` is false, so the card stays an <article>.
        $this->assertStringContainsString('var collapsed = !!sub.collapsed;', $js);

        // …and the fold is actually drawn: a summary styled as the head, with a
        // caret that turns when it opens. Without these it is an unstyled
        // list-item marker on top of a flex row.
        $this->assertStringContainsString('.la-sub[data-collapsed] > .la-sub__head', $css);
        $this->assertStringContainsString('.la-sub[data-collapsed][open] > .la-sub__head::after', $css);
    }

    /**
     * An offer may carry its own stylesheet (`card.css` — SafeCss-cleaned on the
     * server), and injecting it must NOT create a second innerHTML: the renderer
     * builds a <style> ELEMENT and assigns its textContent, which the DOM never
     * re-parses as HTML — a `</style>` inside the CSS cannot break out. Once per
     * offer id, marked, because the same offer renders under several plans.
     */
    public function test_an_offers_css_is_injected_once_as_inert_text(): void
    {
        $js = (string) file_get_contents(base_path(self::PLUGIN_JS));

        // A real element with assigned text — never a string of markup.
        $this->assertStringContainsString("document.createElement('style')", $js);
        $this->assertStringContainsString('style.textContent = String(offer.css);', $js);

        // The marker, and the once-per-offer guard that reads it.
        $this->assertStringContainsString("var OFFER_CSS_ATTR = 'data-lets-offer-css';", $js);
        $this->assertStringContainsString('if (state.mount.querySelector(marker)) { return; }', $js);

        // Wired into the one card renderer, and tolerant of a payload from a
        // LETS that predates the field — a missing `css` injects nothing.
        $this->assertStringContainsString('injectOfferCss(state, offer);', $js);
        $this->assertStringContainsString('if (!offer.css || !state.mount) { return; }', $js);
    }

    /**
     * The renderer's no-innerHTML law, now with exactly one exemption.
     *
     * A merchant may design an offer card in their own HTML, which is cleaned
     * server-side by SafeHtml and scrubbed again here after parsing. That is one
     * assignment, in one function. A second one appearing anywhere else in this
     * file is how the personal area would become a script-injection surface
     * inside somebody else's storefront — so it is counted, not described.
     * Comments are stripped first: the header explains the rule and therefore
     * has to name it.
     */
    public function test_the_renderer_assigns_html_exactly_once(): void
    {
        $js = (string) file_get_contents(base_path(self::PLUGIN_JS));
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $js);

        $this->assertSame(
            1,
            preg_match_all('/innerHTML/', $code),
            'the renderer must assign HTML exactly once — in renderOfferHtml(), on server-sanitized markup',
        );

        // …and that one is followed by the belt-and-braces pass.
        $this->assertMatchesRegularExpression('/innerHTML = offer\.html;\s*\n\s*scrubOfferHtml\(box\);/', $code);
        $this->assertStringContainsString("'script,style,iframe,object,embed,form,noscript'", $js);
    }

    /**
     * The click reaches LETS or the offer does nothing. The plugin forwards the
     * id of the offer and the price the CARD showed — the second as evidence,
     * never as an instruction: the SaaS prices the offer from its own template
     * and refuses the click when the two disagree.
     */
    public function test_the_plugin_forwards_the_offer_and_the_price_it_showed(): void
    {
        $php = (string) file_get_contents(base_path(self::PLUGIN_PHP));

        $act = (string) preg_replace(
            '/^.*function lets_payplus_account_rest_act\(WP_REST_Request \$request\)\s*\{(.*?)\n\}.*$/s',
            '$1',
            $php,
        );
        $this->assertNotSame('', $act, 'lets_payplus_account_rest_act() not found');

        $this->assertStringContainsString("'offer'", $act);
        $this->assertStringContainsString("sanitize_text_field((string) \$request->get_param('offer'))", $act);
        $this->assertStringContainsString("is_numeric(\$request->get_param('amount'))", $act);

        // …and WHICH of the offer's targets was clicked. One offer can carry
        // several products now; without the key the SaaS is asked to guess which
        // one to charge for, and a guess here is somebody's money.
        $this->assertStringContainsString("'target'", $act);
        $this->assertStringContainsString("sanitize_text_field((string) \$request->get_param('target'))", $act);

        // The identity still comes from the WordPress session, never the body.
        $this->assertStringContainsString("'customer_ref' => (string) \$user_id", $act);
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
