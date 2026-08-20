<?php

/**
 * LETS — the shopper's personal area inside WooCommerce → My Account.
 *
 * WHY THIS IS NOT AN IFRAME. The members club is iframed because it is a
 * standalone page a merchant drops anywhere. My Account is different: it lives
 * inside the merchant's theme, and a shopper reads it as part of their shop. An
 * iframe cannot inherit the theme's typography, cannot share its focus order, and
 * needs a height handshake that breaks on every layout change. So the SaaS returns
 * data and `lets-account.js` renders it into the real page DOM. The stylesheet
 * declares no font-family at all — the area inherits the theme's, which is the
 * whole point.
 *
 * IDENTITY. The bootstrap call is made SERVER-side with the plugin's HMAC signer,
 * asserting the logged-in WP user. The browser never holds the shared secret and
 * never states who it is. Browser → plugin is a WP nonce; plugin → SaaS is the
 * HMAC. Exactly the three-layer model the product widget documents.
 *
 * SIGN-IN CODES. LETS issues and verifies the code; WORDPRESS performs the login.
 * We resolve the address or phone to a real WP user here, ask LETS only "did this
 * code match this destination", and call wp_set_auth_cookie() ourselves — so a
 * leaked shared secret still cannot mint a session, and WP stays the authority on
 * identity.
 */
defined('ABSPATH') || exit;

// === CONSTANTS ===

/** Our own My Account endpoints (also the URL segment and the query var). */
define('LETS_ACCOUNT_ENDPOINT', 'lets-subscriptions');

/** How long a bootstrap payload is cached per user (seconds). Short: it carries dates. */
define('LETS_ACCOUNT_CACHE_TTL', 60);

/** Bumping this re-flushes the rewrite rules once per site. */
define('LETS_ACCOUNT_REWRITE_VERSION', '1');

/** The wp_option holding the flushed-rules marker. */
define('LETS_ACCOUNT_REWRITE_OPT', 'lets_payplus_account_rewrite');

/** WP user meta WooCommerce stores the billing phone in — our SMS destination. */
define('LETS_ACCOUNT_PHONE_META', 'billing_phone');

/**
 * Our own mirror of that number, digits only and country code folded.
 *
 * WooCommerce stores whatever the shopper typed at checkout — `050-123 4567`,
 * `+972 50 123 4567`, `0501234567` — so the stored value cannot be matched
 * against a typed one by equality, and a store cannot be scanned row by row
 * either (that is a query per customer on an endpoint anyone can call). This
 * key holds the ONE canonical spelling, so a sign-in is a single indexed lookup
 * no matter how large the customer list is.
 */
define('LETS_ACCOUNT_PHONE_INDEX', '_lets_phone');

/** Bumping this re-runs the one-time backfill of the index above. */
define('LETS_ACCOUNT_PHONE_INDEX_VERSION', '1');
define('LETS_ACCOUNT_PHONE_INDEX_OPT', 'lets_payplus_phone_index');
define('LETS_ACCOUNT_PHONE_INDEX_HOOK', 'lets_payplus_index_phones');

/** Customers mirrored per backfill pass. Small enough to finish inside a cron tick. */
define('LETS_ACCOUNT_PHONE_INDEX_BATCH', 200);

/** Codes a single browser may request before we stop asking LETS at all. */
define('LETS_ACCOUNT_LOCAL_CODE_LIMIT', 8);

/**
 * The coarse ceiling for one address, whatever it asks about.
 *
 * The fine budget above is per shopper (IP + destination) so that a store behind
 * a CDN — where every visitor shares one REMOTE_ADDR — does not put the whole
 * country on one allowance. That alone would let somebody rotate destinations for
 * an unlimited number of user lookups, so a second, much looser bucket caps the
 * total. High enough that a household or an office never reaches it.
 */
define('LETS_ACCOUNT_IP_CODE_LIMIT', 60);

/**
 * Capabilities that disqualify an account from passwordless sign-in. A shopper has
 * none of them; anyone who can edit the site, its products or its orders signs in
 * through WordPress's own login, where the merchant's 2FA plugin still applies.
 */
define('LETS_ACCOUNT_PRIVILEGED_CAPS', array(
    'manage_options',
    'edit_posts',
    'manage_woocommerce',
    'edit_shop_orders',
    // A custom "support" role often holds only these — enough to take over any
    // account on the site, and so more than a one-time code may stand behind.
    'edit_users',
    'promote_users',
    'delete_users',
));

/** Our WooCommerce template overrides — the page shell and the bare dashboard. */
define('LETS_ACCOUNT_TEMPLATE_DIR', plugin_dir_path(LETS_PAYPLUS_FILE) . 'templates/');
define('LETS_ACCOUNT_TEMPLATES', 'myaccount/my-account.php|myaccount/dashboard.php');

/**
 * WooCommerce's login screen — overridden SEPARATELY from the two above.
 *
 * The shell and the dashboard are ours whenever the store is connected. This one
 * is ours only while the merchant actually offers passwordless sign-in: a shop
 * that has not switched it on must keep WooCommerce's own form untouched, and a
 * shop that has switched it on wants the code panel to BE the sign-in screen
 * rather than a second box under a username/password form nobody uses.
 */
define('LETS_ACCOUNT_LOGIN_TEMPLATE', 'myaccount/form-login.php');

/**
 * Quick registration — the "you proved you own this address, now tell us who you
 * are" ticket.
 *
 * The ticket is a random string handed to the browser; what it maps to (the
 * channel and the VERIFIED destination) never leaves the server. Ten minutes is
 * long enough to type a name and short enough that a ticket left in a history
 * entry is worthless.
 */
define('LETS_ACCOUNT_REG_TICKET_TTL', 600);
define('LETS_ACCOUNT_REG_TICKET_PREFIX', 'lets_reg_');

/** Longest first/last name accepted at quick registration. */
define('LETS_ACCOUNT_NAME_MAX', 60);

/** Seconds before "send again" is offered on the code step. */
define('LETS_ACCOUNT_RESEND_SECONDS', 30);

/** Shop-wide shell config (appearance + sign-in), cached apart from any shopper. */
define('LETS_ACCOUNT_SHELL_CACHE', 'lets_account_shell_cfg');
define('LETS_ACCOUNT_SHELL_TTL', 300);

/** A round trip that FAILED is remembered only briefly — see the fetch. */
define('LETS_ACCOUNT_SHELL_TTL_FAILED', 30);

/* -------------------------------------------------------------------------
 * 1. The My Account endpoint
 * ---------------------------------------------------------------------- */

add_action('init', 'lets_payplus_account_register_endpoint');

function lets_payplus_account_register_endpoint()
{
    add_rewrite_endpoint(LETS_ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES);

    // The plugin has no activation hook (it is installed by upload as often as by
    // the installer), so flush once against a version marker rather than on every
    // request — flush_rewrite_rules() on init is a well-known performance trap.
    if (LETS_ACCOUNT_REWRITE_VERSION !== get_option(LETS_ACCOUNT_REWRITE_OPT)) {
        flush_rewrite_rules(false);
        update_option(LETS_ACCOUNT_REWRITE_OPT, LETS_ACCOUNT_REWRITE_VERSION, false);
    }
}

add_filter('woocommerce_get_query_vars', 'lets_payplus_account_query_vars');

function lets_payplus_account_query_vars($vars)
{
    $vars[LETS_ACCOUNT_ENDPOINT] = LETS_ACCOUNT_ENDPOINT;

    return $vars;
}

/**
 * Put our tab right after Dashboard — a subscriber's most common reason to open
 * My Account at all is the subscription, not the address book.
 */
add_filter('woocommerce_account_menu_items', 'lets_payplus_account_menu_items');

function lets_payplus_account_menu_items($items)
{
    if (null === lets_payplus_connection()) {
        return $items;
    }

    $hidden = lets_payplus_account_hidden_endpoints();

    $out = array();
    foreach ($items as $key => $label) {
        if (isset($hidden[$key])) {
            continue;
        }

        $out[$key] = $label;
        if ('dashboard' === $key) {
            $out[LETS_ACCOUNT_ENDPOINT] = lets_payplus_account_menu_label();
        }
    }

    // A theme that removed the dashboard item still gets the tab, at the end.
    if (! isset($out[LETS_ACCOUNT_ENDPOINT])) {
        $out[LETS_ACCOUNT_ENDPOINT] = lets_payplus_account_menu_label();
    }

    return $out;
}

function lets_payplus_account_menu_label()
{
    return lets_payplus_account_is_he() ? 'המנויים שלי' : 'My subscriptions';
}

/**
 * WooCommerce endpoints the merchant has switched off, as a lookup.
 *
 * The "sections" setting has always said it "hides the section from every
 * shopper", but for WooCommerce's OWN tabs it only ever hid the block we draw —
 * the navigation kept the tab, so unchecking Orders did nothing a shopper could
 * see. These four keys map a section onto the endpoint it names, which makes the
 * setting mean what it says, and gives a shop with nothing downloadable a way to
 * take the empty Downloads tab out of its customers' navigation.
 *
 * FAIL-OPEN. An unreadable config hides nothing: a shopper losing their orders
 * tab because LETS was briefly unreachable is far worse than an empty tab.
 *
 * @return array<string, true>
 */
function lets_payplus_account_hidden_endpoints()
{
    $map = array(
        'orders' => 'orders',
        'downloads' => 'downloads',
        'edit-account' => 'profile',
        'edit-address' => 'addresses',
    );

    $config = lets_payplus_account_shell_config();
    $sections = isset($config['sections']) ? (array) $config['sections'] : array();

    if (array() === $sections) {
        return array();
    }

    $hidden = array();
    foreach ($map as $endpoint => $section) {
        if (! in_array($section, $sections, true)) {
            $hidden[$endpoint] = true;
        }
    }

    return $hidden;
}

add_action('woocommerce_account_' . LETS_ACCOUNT_ENDPOINT . '_endpoint', 'lets_payplus_account_render_endpoint');

function lets_payplus_account_render_endpoint()
{
    echo lets_payplus_account_markup(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
}

/**
 * The dashboard tab too. WooCommerce's own dashboard is three sentences and a
 * link; a subscriber who lands there should see their subscription, not prose.
 */
add_action('woocommerce_account_dashboard', 'lets_payplus_account_render_dashboard', 5);

function lets_payplus_account_render_dashboard()
{
    echo lets_payplus_account_markup(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
}

/* -------------------------------------------------------------------------
 * 2. The mount point + assets
 * ---------------------------------------------------------------------- */

/**
 * The mount div, plus a server-rendered fallback so the area is never blank
 * without JavaScript. The fallback is deliberately thin — the next charge date
 * and the status, which is what a shopper opens this page to check.
 *
 * @return string
 */
function lets_payplus_account_markup()
{
    if (null === lets_payplus_connection() || ! is_user_logged_in()) {
        return '';
    }

    $model = lets_payplus_account_model();
    if (empty($model)) {
        return '';
    }

    lets_payplus_account_enqueue($model);

    ob_start();
    ?>
    <div id="lets-account" class="lets-acct">
        <noscript>
            <?php echo lets_payplus_account_fallback($model); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.?>
        </noscript>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * Register + enqueue the canonical stylesheet and renderer, and hand the payload
 * to the page. wp_localize_script (not an inline JSON blob) so WordPress does the
 * escaping and the data cannot break out of the script tag.
 *
 * @param  array  $model
 */
function lets_payplus_account_enqueue($model)
{
    wp_enqueue_style(
        'lets-payplus-account',
        LETS_PAYPLUS_URL . 'assets/css/lets-account.css',
        array(),
        LETS_PAYPLUS_VERSION
    );

    wp_enqueue_script(
        'lets-payplus-account',
        LETS_PAYPLUS_URL . 'assets/js/lets-account.js',
        array(),
        LETS_PAYPLUS_VERSION,
        true
    );

    wp_localize_script('lets-payplus-account', 'LetsAccountData', array(
        'model' => $model,
        // {action} is substituted by the renderer — one registered route, six verbs.
        // The placeholder is appended AFTER esc_url_raw: WordPress strips curly
        // braces from URLs, and an endpoint delivered as ".../act/action" makes
        // the renderer's replace() a no-op — every verb then posts to a route
        // that does not exist and every click fails with the generic toast.
        'endpoint' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/account/act/')) . '{action}',
        'nonce'    => wp_create_nonce('wp_rest'),
        // Where the sign-in CTA points when the visitor is logged out: the
        // My Account page, whose login form carries our sign-in panel.
        'signIn' => esc_url_raw(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url()),
    ));

    wp_add_inline_script(
        'lets-payplus-account',
        'window.addEventListener("DOMContentLoaded",function(){'
        . 'var m=document.getElementById("lets-account");'
        . 'if(m&&window.LetsAccount&&window.LetsAccountData){'
        . 'window.LetsAccount.render(m,window.LetsAccountData.model,{'
        . 'endpoint:window.LetsAccountData.endpoint,nonce:window.LetsAccountData.nonce,'
        . 'signInUrl:window.LetsAccountData.signIn});}});'
    );
}

/**
 * No-JS fallback. Every value is escaped; nothing is actionable, because an
 * action needs the REST call the missing JavaScript would have made.
 *
 * @param  array  $model
 * @return string
 */
function lets_payplus_account_fallback($model)
{
    $subs = isset($model['subscriptions']) && is_array($model['subscriptions']) ? $model['subscriptions'] : array();
    $copy = isset($model['copy']) && is_array($model['copy']) ? $model['copy'] : array();

    ob_start();
    echo '<div class="la-card">';

    if (empty($subs)) {
        echo '<p class="la-empty">' . esc_html(isset($copy['empty_subscriptions']) ? $copy['empty_subscriptions'] : '') . '</p>';
    } else {
        echo '<ul>';
        foreach ($subs as $sub) {
            $title = isset($sub['title']) ? (string) $sub['title'] : '';
            $next = isset($sub['next_charge_at']) ? (string) $sub['next_charge_at'] : '';
            $state = isset($copy['status_' . $sub['status']]) ? $copy['status_' . $sub['status']] : (string) $sub['status'];

            echo '<li>' . esc_html($title) . ' — ' . esc_html($state);
            if ('' !== $next) {
                echo ' · ' . esc_html(isset($copy['next_charge']) ? $copy['next_charge'] : '') . ' ' . esc_html($next);
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    echo '</div>';

    return (string) ob_get_clean();
}

/* -------------------------------------------------------------------------
 * 2.5 The page takeover
 *
 * My Account is not a widget dropped into a theme column. The plugin renders
 * the whole screen: its own shell, its own navigation, its own width. What it
 * does NOT do is reimplement WooCommerce's own tabs — orders, addresses and
 * account details keep WooCommerce's markup and behaviour (address validation
 * and password changes are solved, and rebuilding them would be risk for no
 * gain) and are re-skinned with the same tokens so nothing on the page looks
 * like a different product.
 * ---------------------------------------------------------------------- */

add_filter('body_class', 'lets_payplus_account_body_class');

function lets_payplus_account_body_class($classes)
{
    if (lets_payplus_account_is_ours()) {
        $classes[] = 'lets-acct-shell';
    }

    return $classes;
}

/** True on a My Account screen of a connected store. */
function lets_payplus_account_is_ours()
{
    return function_exists('is_account_page') && is_account_page() && null !== lets_payplus_connection();
}

/**
 * The shell's assets, on EVERY account tab — the stylesheet re-skins
 * WooCommerce's own screens, so it cannot be enqueued only where we render.
 */
add_action('wp_enqueue_scripts', 'lets_payplus_account_shell_assets');

function lets_payplus_account_shell_assets()
{
    // Checkout carries WooCommerce's "returning customer?" login form, and our
    // sign-in panel renders inside it. Enqueuing there from the template would put
    // the stylesheet in the footer and paint the panel unstyled first.
    $on_checkout = function_exists('is_checkout') && is_checkout() && null !== lets_payplus_connection();
    $ours = lets_payplus_account_is_ours();

    // Some themes put [woocommerce_my_account] on a page of their own choosing,
    // where is_account_page() is false. The panel still renders there, so without
    // this its stylesheet would arrive in the footer and it would paint bare.
    $on_shortcode = ! $ours
        && function_exists('wc_post_content_has_shortcode')
        && wc_post_content_has_shortcode('woocommerce_my_account')
        && null !== lets_payplus_connection();

    if (! $ours && ! $on_checkout && ! $on_shortcode) {
        return;
    }

    // CHECKOUT NEVER WAITS ON US. Reading the config can cost a round trip to
    // LETS on a cache miss, and the merchant's money page must not block on our
    // availability to style a sign-in panel. On checkout we take the config only
    // if it is already cached; the panel falls back to its default tokens, which
    // is a slightly off-brand accent and not a stalled checkout.
    if ($on_checkout && ! $ours && ! lets_payplus_account_shell_config_cached()) {
        wp_enqueue_style(
            'lets-payplus-account',
            LETS_PAYPLUS_URL . 'assets/css/lets-account.css',
            array(),
            LETS_PAYPLUS_VERSION
        );

        return;
    }

    // Heebo is fetched ONLY when the merchant asked for it. The default is the
    // shop's own typeface, and a webfont nobody chose is a render-blocking
    // request on somebody else's storefront.
    $deps = array();
    if ('heebo' === lets_payplus_account_font()) {
        wp_enqueue_style('lets-payplus-heebo');
        $deps[] = 'lets-payplus-heebo';
    }

    wp_enqueue_style(
        'lets-payplus-account',
        LETS_PAYPLUS_URL . 'assets/css/lets-account.css',
        $deps,
        LETS_PAYPLUS_VERSION
    );

    // The merchant's brand, as tokens on the shell. A stylesheet rule rather
    // than a style="" attribute: the markup stays free of inline CSS, and
    // WordPress does the escaping on the way out.
    $tokens = lets_payplus_account_shell_tokens();
    if ('' !== $tokens) {
        wp_add_inline_style('lets-payplus-account', $tokens);
    }

    // Measures how much room the theme actually gave the page. Never required:
    // without it the shell simply keeps the width the theme handed it. Checkout
    // has no shell to measure — it borrows the stylesheet for the panel alone.
    if (lets_payplus_account_is_ours()) {
        wp_enqueue_script(
            'lets-payplus-account-shell',
            LETS_PAYPLUS_URL . 'assets/js/lets-account-shell.js',
            array(),
            LETS_PAYPLUS_VERSION,
            true
        );
    }
}

/**
 * `.lets-shell` + `.la-nav` token overrides from the merchant's appearance.
 *
 * Only two shapes are ever emitted — a #hex colour and an integer px radius —
 * and anything that is not exactly that is dropped rather than escaped, so a
 * poisoned value cannot become a CSS declaration.
 *
 * @return string
 */
function lets_payplus_account_shell_tokens()
{
    $appearance = lets_payplus_account_shell_config();
    $appearance = isset($appearance['appearance']) ? (array) $appearance['appearance'] : array();

    $rules = array();

    $accent = lets_payplus_account_hex(isset($appearance['accent']) ? $appearance['accent'] : '');
    if ('' !== $accent) {
        $rules[] = '--la-accent:' . $accent;
        $rules[] = '--la-accent-soft:color-mix(in srgb, ' . $accent . ' 12%, transparent)';
    }

    $accentText = lets_payplus_account_hex(isset($appearance['accent_text']) ? $appearance['accent_text'] : '');
    if ('' !== $accentText) {
        $rules[] = '--la-accent-fg:' . $accentText;
    }

    $radius = isset($appearance['radius']) ? (string) $appearance['radius'] : '';
    if (preg_match('/^\d{1,2}px$/', $radius)) {
        $rules[] = '--la-radius:' . $radius;
    }

    // `.lets-acct` is in the list because the sign-in panel is a bare `.lets-acct`
    // with no shell around it on checkout — and because the stylesheet declares
    // the default tokens ON that class, so an inherited accent would lose to it.
    // The rendered account area still wins: its renderer writes the same custom
    // properties inline on the mount, and an inline declaration beats a rule.
    return $rules ? '.lets-shell,.la-nav,.lets-acct{' . implode(';', $rules) . '}' : '';
}

/** A #rrggbb / #rgb colour, or '' for anything else. */
function lets_payplus_account_hex($value)
{
    $value = trim((string) $value);

    return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) ? $value : '';
}

/**
 * The enum half of the appearance, as attributes for the shell and the nav.
 * Read by our my-account.php template and by the navigation below.
 *
 * @return string
 */
/**
 * The typeface the merchant chose, from the same payload the shell reads.
 * `theme` — the default and the recommendation — means we declare nothing and
 * the area inherits the shop's own type.
 *
 * @return string one of theme|heebo|system
 */
function lets_payplus_account_font()
{
    $config = lets_payplus_account_shell_config();
    $appearance = isset($config['appearance']) ? (array) $config['appearance'] : array();
    $font = isset($appearance['font']) ? (string) $appearance['font'] : '';

    return in_array($font, array('theme', 'heebo', 'system'), true) ? $font : 'theme';
}

function lets_payplus_account_shell_attributes()
{
    $config = lets_payplus_account_shell_config();
    $appearance = isset($config['appearance']) ? (array) $config['appearance'] : array();

    $enums = array(
        'data-theme'   => array('light', 'dark', 'auto'),
        'data-density' => array('comfortable', 'compact'),
        'data-card'    => array('outlined', 'flat', 'raised'),
        'data-font'    => array('theme', 'heebo', 'system'),
    );
    $keys = array(
        'data-theme'   => 'theme',
        'data-density' => 'density',
        'data-card'    => 'card',
        'data-font'    => 'font',
    );

    $out = '';
    foreach ($enums as $attribute => $allowed) {
        $value = isset($appearance[$keys[$attribute]]) ? (string) $appearance[$keys[$attribute]] : '';
        if (in_array($value, $allowed, true)) {
            $out .= ' ' . $attribute . '="' . esc_attr($value) . '"';
        }
    }

    return $out;
}

/**
 * The area's own reading direction, for OUR chrome only.
 *
 * A merchant can run a Hebrew personal area on an English store; the navigation
 * then has to mirror even though the theme does not. WooCommerce's own screens
 * are deliberately left in the site's direction — half a page in the shop's
 * language laid out the other way round reads worse than either alone.
 *
 * @return string
 */
function lets_payplus_account_dir_attribute()
{
    $config = lets_payplus_account_shell_config();
    $dir = isset($config['appearance']['dir']) ? (string) $config['appearance']['dir'] : '';

    return in_array($dir, array('rtl', 'ltr'), true) ? ' dir="' . esc_attr($dir) . '"' : '';
}

/**
 * Is the shop-wide config already in hand, i.e. can it be read without a round
 * trip? Checkout asks before it reads — see lets_payplus_account_shell_assets().
 *
 * @return bool
 */
function lets_payplus_account_shell_config_cached()
{
    return is_array(get_transient(LETS_ACCOUNT_SHELL_CACHE));
}

/**
 * Appearance + sign-in settings for the shop, with no shopper in the request.
 *
 * Shop-wide and cached apart from any customer, so painting the nav on the
 * orders tab does not cost a per-user round trip — and so a signed-out visitor
 * on the login form is served from the same place.
 *
 * @return array{appearance: array, login: array{enabled: bool, channel: string, google_client_id: string}}
 */
function lets_payplus_account_shell_config()
{
    $cached = get_transient(LETS_ACCOUNT_SHELL_CACHE);
    if (is_array($cached)) {
        return $cached;
    }

    $config = array(
        'appearance' => array(),
        // The VISIBLE section keys, in the merchant's order. Read by the account
        // navigation, which hides a WooCommerce tab whose section is switched
        // off. Empty means "we do not know" and nothing is hidden.
        'sections'   => array(),
        'login'      => array('enabled' => false, 'channel' => 'email', 'google_client_id' => ''),
        // Storefront purchase rules. They ride the SHELL because the shell is the
        // cached half: the storefront must know whether the one-subscription rule
        // is on before every add-to-cart, and that answer is shop-wide.
        'purchase'   => array('single_subscription' => false),
    );

    $result = lets_payplus_signed_post('/api/woocommerce/account/bootstrap', array(
        'customer_ref' => '',
        'locale'       => lets_payplus_account_site_locale(),
    ));

    // A failed round trip is not an answer, and caching it for the full window
    // would take the sign-in panel off the login page for five minutes because of
    // one slow request. Remember the failure briefly — long enough not to retry on
    // every hit while LETS is down, short enough to recover on its own.
    if (is_wp_error($result) || empty($result['account'])) {
        set_transient(LETS_ACCOUNT_SHELL_CACHE, $config, LETS_ACCOUNT_SHELL_TTL_FAILED);

        return $config;
    }

    if (! empty($result['account'])) {
        $account = (array) $result['account'];

        if (! empty($account['appearance'])) {
            $config['appearance'] = (array) $account['appearance'];
        }
        if (! empty($account['sections'])) {
            $config['sections'] = array_values(array_map('strval', (array) $account['sections']));
        }
        if (! empty($account['login'])) {
            $login = (array) $account['login'];
            $config['login'] = array(
                'enabled' => ! empty($login['enabled']),
                'channel' => isset($login['channel']) ? (string) $login['channel'] : 'email',
                // Public by design — it is rendered into the GIS button — but the
                // verify endpoint still checks every credential's `aud` against it.
                'google_client_id' => isset($login['google_client_id']) ? (string) $login['google_client_id'] : '',
            );
        }
        if (! empty($account['purchase'])) {
            $purchase = (array) $account['purchase'];
            $config['purchase'] = array(
                'single_subscription' => ! empty($purchase['single_subscription']),
            );
        }
    }

    set_transient(LETS_ACCOUNT_SHELL_CACHE, $config, LETS_ACCOUNT_SHELL_TTL);

    return $config;
}

/**
 * Serve our own my-account.php (the shell) and dashboard.php (which drops
 * WooCommerce's three sentences of prose, since the area below replaces them).
 *
 * OURS WINS, INCLUDING OVER A THEME OVERRIDE. The personal area is the product
 * the merchant installed this plugin for; a theme's `my-account.php` — which on
 * most Israeli themes is WooCommerce's own file with the header swapped — is
 * not a decision to keep it out. Themes lose the SHELL only: their hooks all
 * still fire from our template, and WooCommerce's own tabs keep their markup.
 *
 * The escape hatch is a filter, not a file, so opting out is deliberate:
 *
 *     add_filter('lets_payplus_account_own_template', '__return_false');
 *
 * @param  string  $template
 * @param  string  $template_name
 * @return string
 */
add_filter('woocommerce_locate_template', 'lets_payplus_account_locate_template', 99, 2);

function lets_payplus_account_locate_template($template, $template_name)
{
    if (null === lets_payplus_connection()) {
        return $template;
    }

    // THE SIGN-IN SCREEN, on its own condition. A merchant who never switched
    // code sign-in on keeps WooCommerce's login form exactly as it was — this
    // override exists to REPLACE that form with the code panel, and replacing it
    // with nothing would be a store with no way in.
    if (LETS_ACCOUNT_LOGIN_TEMPLATE === $template_name) {
        if (! lets_payplus_account_login_takeover()) {
            return $template;
        }

        /** @see the filter documented above — the same escape hatch covers this file. */
        if (! apply_filters('lets_payplus_account_own_template', true, $template_name)) {
            return $template;
        }

        $login_template = LETS_ACCOUNT_TEMPLATE_DIR . $template_name;

        return file_exists($login_template) ? $login_template : $template;
    }

    if (! in_array($template_name, explode('|', LETS_ACCOUNT_TEMPLATES), true)) {
        return $template;
    }

    /**
     * Filters whether the plugin serves its own My Account templates.
     *
     * @param  bool  $use  Whether to override the theme.
     * @param  string  $template_name  The template being located.
     */
    if (! apply_filters('lets_payplus_account_own_template', true, $template_name)) {
        return $template;
    }

    $ours = LETS_ACCOUNT_TEMPLATE_DIR . $template_name;

    return file_exists($ours) ? $ours : $template;
}

/**
 * The sign-in screen, decided AFTER WooCommerce's template cache.
 *
 * wc_get_template() remembers which file a template name resolved to — in an
 * OBJECT cache, which on a store running Redis or Memcached survives the
 * request. `woocommerce_locate_template` runs on the miss only, so on such a
 * store the answer from before this feature existed would keep being served and
 * the new sign-in screen would never appear (and, the other way round, a shop
 * that switched code sign-in off would keep getting ours until somebody flushed
 * a cache they do not know about).
 *
 * This filter runs after the cache is consulted, so it is the one that actually
 * decides. The other two templates keep the older seam: they are ours whenever
 * the store is connected, which is a condition that does not change between
 * requests, so a cached answer is never the wrong one.
 *
 * @param  string  $template
 * @param  string  $template_name
 * @return string
 */
add_filter('wc_get_template', 'lets_payplus_account_login_template', 99, 2);

function lets_payplus_account_login_template($template, $template_name)
{
    if (LETS_ACCOUNT_LOGIN_TEMPLATE !== $template_name || null === lets_payplus_connection()) {
        return $template;
    }

    if (! lets_payplus_account_login_takeover()) {
        return $template;
    }

    if (! apply_filters('lets_payplus_account_own_template', true, $template_name)) {
        return $template;
    }

    $login_template = LETS_ACCOUNT_TEMPLATE_DIR . $template_name;

    return file_exists($login_template) ? $login_template : $template;
}

/**
 * Our navigation replaces WooCommerce's, on account pages only.
 *
 * The action is kept (rather than the template overridden) so a theme that
 * calls `woocommerce_account_navigation` itself still gets our nav, and so the
 * before/after hooks other plugins use to add links keep firing.
 */
add_action('template_redirect', 'lets_payplus_account_take_over_navigation', 99);

function lets_payplus_account_take_over_navigation()
{
    if (! lets_payplus_account_is_ours()) {
        return;
    }

    /**
     * EVERY callback goes, not just WooCommerce's own: a theme that printed its
     * navigation on this hook would otherwise render a second, unstyled menu
     * beside ours. The two extension points plugins actually use —
     * `woocommerce_before_account_navigation` and `..._after_...` — are fired
     * from inside our navigation, so links other plugins add keep appearing.
     */
    remove_all_actions('woocommerce_account_navigation');
    add_action('woocommerce_account_navigation', 'lets_payplus_account_navigation');
}

function lets_payplus_account_navigation()
{
    $he = lets_payplus_account_is_he();
    $items = wc_get_account_menu_items();
    $logout = null;

    if (isset($items['customer-logout'])) {
        $logout = $items['customer-logout'];
        unset($items['customer-logout']);
    }

    ?>
    <nav class="woocommerce-MyAccount-navigation la-nav"<?php echo lets_payplus_account_shell_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed enum attributes, escaped at source.?><?php echo lets_payplus_account_dir_attribute(); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed enum attribute.?>
         aria-label="<?php echo esc_attr($he ? 'ניווט באזור האישי' : 'Account navigation'); ?>">
        <div class="la-nav__inner">
            <?php
            do_action('woocommerce_before_account_navigation');
    lets_payplus_account_identity();
    ?>
            <ul>
                <?php foreach ($items as $endpoint => $label) : ?>
                    <li class="<?php echo esc_attr(wc_get_account_menu_item_classes($endpoint)); ?>">
                        <a href="<?php echo esc_url(wc_get_account_endpoint_url($endpoint)); ?>">
                            <?php lets_payplus_account_icon($endpoint); ?>
                            <span class="la-nav__label"><?php echo esc_html($label); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if (null !== $logout) : ?>
                <div class="la-nav__foot">
                    <ul>
                        <li class="<?php echo esc_attr(wc_get_account_menu_item_classes('customer-logout')); ?>">
                            <a href="<?php echo esc_url(wc_get_account_endpoint_url('customer-logout')); ?>">
                                <?php lets_payplus_account_icon('customer-logout'); ?>
                                <span class="la-nav__label"><?php echo esc_html($logout); ?></span>
                            </a>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>

            <?php do_action('woocommerce_after_account_navigation'); ?>
        </div>
    </nav>
    <?php
}

/**
 * Who is signed in. On a shared machine this is the first thing worth being
 * sure of, and it is the line a theme's header almost never shows.
 */
function lets_payplus_account_identity()
{
    $user = wp_get_current_user();
    if (! $user || ! $user->ID) {
        return;
    }

    $name = (string) ($user->display_name ?: $user->user_login);
    $initial = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);

    ?>
    <div class="la-nav__me">
        <span class="la-nav__avatar" aria-hidden="true"><?php echo esc_html($initial); ?></span>
        <span class="la-nav__who">
            <span class="la-nav__name"><?php echo esc_html($name); ?></span>
            <span class="la-nav__email"><?php echo esc_html($user->user_email); ?></span>
        </span>
    </div>
    <?php
}

/**
 * One line-art icon per endpoint, from a fixed table. Echoed unescaped because
 * every string here is a literal in this file — no value from the database, the
 * request or the merchant ever reaches it.
 *
 * @param  string  $endpoint
 */
function lets_payplus_account_icon($endpoint)
{
    $paths = array(
        'dashboard'           => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        LETS_ACCOUNT_ENDPOINT => '<path d="M20 12a8 8 0 1 1-2.34-5.66"/><path d="M20 4v4h-4"/>',
        'orders'              => '<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4"/><path d="M9 12h7M9 16h5"/>',
        'downloads'           => '<path d="M12 4v10"/><path d="m8 11 4 4 4-4"/><path d="M5 19h14"/>',
        'edit-address'        => '<path d="M12 21s7-6.1 7-11a7 7 0 1 0-14 0c0 4.9 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/>',
        'payment-methods'     => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19"/>',
        'edit-account'        => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
        'subscriptions'       => '<path d="M20 12a8 8 0 1 1-2.34-5.66"/><path d="M20 4v4h-4"/>',
        'customer-logout'     => '<path d="M15 4h3.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H15"/><path d="M10 8 6 12l4 4"/><path d="M6 12h9"/>',
    );

    $path = isset($paths[$endpoint]) ? $paths[$endpoint] : '<circle cx="12" cy="12" r="8"/>';

    echo '<span class="la-nav__icon" aria-hidden="true">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
        . $path // phpcs:ignore WordPress.Security.EscapeOutput -- literal markup from the table above.
        . '</svg></span>';
}

/**
 * A title above WooCommerce's own screens, so a tab never opens on a bare
 * table. The dashboard and our own tab draw their own heading and are skipped.
 */
add_action('woocommerce_account_content', 'lets_payplus_account_tab_heading', 1);

function lets_payplus_account_tab_heading()
{
    if (null === lets_payplus_connection()) {
        return;
    }

    foreach (wc_get_account_menu_items() as $endpoint => $label) {
        if ('dashboard' === $endpoint || LETS_ACCOUNT_ENDPOINT === $endpoint || 'customer-logout' === $endpoint) {
            continue;
        }
        if (is_wc_endpoint_url($endpoint)) {
            echo '<h2 class="la-shell__title">' . esc_html($label) . '</h2>';

            return;
        }
    }
}

/* -------------------------------------------------------------------------
 * 3. The bootstrap payload
 * ---------------------------------------------------------------------- */

/**
 * The whole personal area, fetched server-side over HMAC and cached briefly per
 * user. Any action busts the cache, so a shopper never sees a stale card after
 * their own edit.
 *
 * @return array
 */
function lets_payplus_account_model()
{
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return array();
    }

    $cache_key = 'lets_account_' . $user_id;
    $cached = get_transient($cache_key);
    if (is_array($cached) && ! empty($cached)) {
        return $cached;
    }

    $model = lets_payplus_account_fetch($user_id);
    if (! empty($model)) {
        set_transient($cache_key, $model, LETS_ACCOUNT_CACHE_TTL);
    }

    return $model;
}

/**
 * @param  int  $user_id
 * @return array
 */
function lets_payplus_account_fetch($user_id)
{
    $user = get_userdata($user_id);
    if (! $user) {
        return array();
    }

    $result = lets_payplus_signed_post('/api/woocommerce/account/bootstrap', array(
        // The SAME reference the gateway records on the ledger, so the
        // subscriptions we show are the subscriptions they pay for.
        'customer_ref' => (string) $user_id,
        'email'        => (string) $user->user_email,
        'name'         => (string) $user->display_name,
        'phone'        => (string) get_user_meta($user_id, LETS_ACCOUNT_PHONE_META, true),
        'locale'       => lets_payplus_account_site_locale(),
    ));

    if (is_wp_error($result) || empty($result['account'])) {
        return array();
    }

    return (array) $result['account'];
}

function lets_payplus_account_flush_cache($user_id)
{
    delete_transient('lets_account_' . (int) $user_id);
}

/**
 * Forget the shop-wide shell + sign-in config.
 *
 * Without this, a merchant who edits the area from WP-ADMIN watches their own
 * shop ignore them for up to five minutes with no way to hurry it along.
 *
 * The sign-in settings themselves live in the LETS dashboard, which cannot reach
 * into WordPress — a change made there is still picked up when the cache expires.
 * The action exists so that a future webhook (or a "refresh now" button) has one
 * place to call.
 */
function lets_payplus_account_flush_shell_config()
{
    delete_transient(LETS_ACCOUNT_SHELL_CACHE);
}

add_action('lets_payplus_settings_changed', 'lets_payplus_account_flush_shell_config');

/* -------------------------------------------------------------------------
 * 4. Browser → plugin REST (nonce) → SaaS (HMAC)
 * ---------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route(LETS_PAYPLUS_REST_NS, '/account/act/(?P<action>[a-z_]+)', array(
        'methods'             => 'POST',
        'callback'            => 'lets_payplus_account_rest_act',
        'permission_callback' => 'lets_payplus_account_rest_permission',
    ));

    // Sign-in is by definition reachable while logged OUT, so it uses the shared
    // storefront nonce rather than the logged-in permission check above.
    register_rest_route(LETS_PAYPLUS_REST_NS, '/account/code/request', array(
        'methods'             => 'POST',
        'callback'            => 'lets_payplus_account_rest_code_request',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
    register_rest_route(LETS_PAYPLUS_REST_NS, '/account/code/verify', array(
        'methods'             => 'POST',
        'callback'            => 'lets_payplus_account_rest_code_verify',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
    // The second half of a verify that found nobody: the shopper proved the
    // address is theirs, and now names themselves so the store has a customer.
    register_rest_route(LETS_PAYPLUS_REST_NS, '/account/code/register', array(
        'methods'             => 'POST',
        'callback'            => 'lets_payplus_account_rest_code_register',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
    register_rest_route(LETS_PAYPLUS_REST_NS, '/account/google', array(
        'methods'             => 'POST',
        'callback'            => 'lets_payplus_account_rest_google',
        'permission_callback' => 'lets_payplus_rest_permission',
    ));
});

/** Acting on a subscription needs a valid nonce AND a logged-in user. */
function lets_payplus_account_rest_permission(WP_REST_Request $request)
{
    $nonce = (string) $request->get_header('X-WP-Nonce');
    if (! wp_verify_nonce($nonce, 'wp_rest')) {
        return new WP_Error('lets_bad_nonce', 'Invalid nonce.', array('status' => 403));
    }

    return is_user_logged_in() ? true : new WP_Error('lets_not_logged_in', 'Sign in first.', array('status' => 401));
}

/**
 * Forward one verb. The identity is taken from the WORDPRESS SESSION, never from
 * the request body — a shopper cannot post somebody else's customer_ref, and the
 * SaaS re-checks ownership on its own side regardless.
 */
function lets_payplus_account_rest_act(WP_REST_Request $request)
{
    $user_id = get_current_user_id();
    $user = get_userdata($user_id);
    $action = sanitize_key((string) $request->get_param('action'));

    $result = lets_payplus_signed_post('/api/woocommerce/account/subscriptions/' . $action, array(
        'customer_ref' => (string) $user_id,
        'email'        => (string) ($user ? $user->user_email : ''),
        'name'         => (string) ($user ? $user->display_name : ''),
        'subscription' => sanitize_text_field((string) $request->get_param('subscription')),
        'date'         => sanitize_text_field((string) $request->get_param('date')),
        // Which offer was clicked, WHICH of its targets, and the price the CARD
        // showed. One offer can now put several products in front of a shopper —
        // a plan to switch to, a one-off to buy on the saved card — so the offer
        // id alone no longer names what was accepted. The amount is forwarded as
        // evidence, never as an instruction: LETS prices the target from its own
        // template and refuses the click when the two disagree.
        'offer'        => sanitize_text_field((string) $request->get_param('offer')),
        'target'       => sanitize_text_field((string) $request->get_param('target')),
        'amount'       => is_numeric($request->get_param('amount')) ? (string) $request->get_param('amount') : '',
        'line_items'   => lets_payplus_account_clean_items($request->get_param('line_items')),
        'locale'       => lets_payplus_account_site_locale(),
    ));

    // The card the shopper just changed must not be served from the cache.
    lets_payplus_account_flush_cache($user_id);

    if (! is_wp_error($result) && ! empty($result['account'])) {
        set_transient('lets_account_' . $user_id, (array) $result['account'], LETS_ACCOUNT_CACHE_TTL);
    }

    return lets_payplus_rest_response($result);
}

/**
 * Product ids and quantities only. Price is not forwarded even if the browser
 * sends one — the SaaS drops it too, but a request that never carries it cannot
 * be misread by a future change on either side.
 *
 * @param  mixed  $rows
 * @return array
 */
function lets_payplus_account_clean_items($rows)
{
    if (! is_array($rows)) {
        return array();
    }

    $out = array();
    foreach ($rows as $row) {
        if (! is_array($row) || empty($row['product_id'])) {
            continue;
        }
        $out[] = array(
            'product_id' => (string) absint($row['product_id']),
            'quantity'   => max(1, absint(isset($row['quantity']) ? $row['quantity'] : 1)),
        );
    }

    return $out;
}

/* -------------------------------------------------------------------------
 * 5. Sign in with a code
 * ---------------------------------------------------------------------- */

/**
 * Ask LETS to send a code.
 *
 * A CODE IS SENT WHETHER OR NOT THE ADDRESS HAS AN ACCOUNT. It used to be sent
 * only to a resolved WP user, which meant a shopper the store had never seen was
 * told "a code is on its way" and then waited for an SMS that was never sent —
 * the polite lie the enumeration defence required, and a dead end. Now the code
 * goes out either way: an address that turns out to have no user finishes at the
 * quick-registration step instead, so the sentence is true for everyone.
 *
 * The answer is still the SAME for every caller. An endpoint that distinguishes
 * "we know you" from "we do not" is an account-enumeration oracle, and it would
 * leak the merchant's customer list to anyone with a browser.
 *
 * The two things that DO stop a send: a destination that is not a plausible
 * address or handset at all (a code there is pure cost), and a destination that
 * resolves to a privileged account (a six-digit code must never be a route into
 * the back office — see lets_payplus_account_is_privileged()).
 */
function lets_payplus_account_rest_code_request(WP_REST_Request $request)
{
    $channel = 'sms' === $request->get_param('channel') ? 'sms' : 'email';
    $destination = sanitize_text_field((string) $request->get_param('destination'));

    // A local counter as well as the SaaS-side throttle: an unauthenticated
    // endpoint should not be able to spend the shop's SMS budget just because a
    // network hop is cheap.
    if (! lets_payplus_account_spend_budget('rq', $destination)) {
        return rest_ensure_response(array('ok' => true));
    }

    if (! lets_payplus_account_destination_is_plausible($channel, $destination)) {
        return rest_ensure_response(array('ok' => true));
    }

    $user = lets_payplus_account_find_user($channel, $destination);

    // The shop owner's own address is the one an attacker knows. Same answer as
    // a stranger's, and nothing sent.
    if ($user && lets_payplus_account_is_privileged($user)) {
        return rest_ensure_response(array('ok' => true));
    }

    lets_payplus_signed_post('/api/woocommerce/account/otp/request', array(
        'channel'     => $channel,
        'destination' => $destination,
        'ip'          => lets_payplus_account_client_ip(),
    ));

    return rest_ensure_response(array('ok' => true));
}

/**
 * Could this string reach anybody at all?
 *
 * Not validation for the shopper's benefit — the panel does that in the browser —
 * but a floor under the SMS bill. `asdf` is not a handset and `07` is not a phone
 * number, and every send costs the merchant money.
 *
 * @param  string  $channel
 * @param  string  $destination
 * @return bool
 */
function lets_payplus_account_destination_is_plausible($channel, $destination)
{
    $destination = trim((string) $destination);

    if ('email' === $channel) {
        return '' !== $destination && (bool) is_email($destination);
    }

    $digits = lets_payplus_account_digits($destination);
    $length = strlen($digits);

    return $length >= 9 && $length <= 15;
}

/**
 * Verify a code and, on success, log the WordPress user in — or open the door to
 * quick registration when the verified address belongs to nobody yet.
 *
 * LETS attests only that the code matched the destination. WORDPRESS decides who
 * that destination belongs to and issues the session — which is why a leaked
 * shared secret cannot mint a login on its own. The same split holds for the new
 * account: LETS says "this handset answered", and only then does WordPress agree
 * to create a customer for it.
 */
function lets_payplus_account_rest_code_verify(WP_REST_Request $request)
{
    $channel = 'sms' === $request->get_param('channel') ? 'sms' : 'email';
    $destination = sanitize_text_field((string) $request->get_param('destination'));
    $code = sanitize_text_field((string) $request->get_param('code'));

    // Guessing is already capped per code on the SaaS side; this bucket is about
    // load, since every call here runs a user lookup on an open endpoint.
    if (! lets_payplus_account_spend_budget('vf', $destination)) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'rejected'));
    }

    $user = lets_payplus_account_find_user($channel, $destination);

    // Checked BEFORE the code is even offered up: a privileged account is not a
    // sign-in this endpoint performs, so there is nothing to verify.
    if ($user && lets_payplus_account_is_privileged($user)) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'rejected'));
    }

    $result = lets_payplus_signed_post('/api/woocommerce/account/otp/verify', array(
        'channel'     => $channel,
        'destination' => $destination,
        'code'        => $code,
    ));

    if (is_wp_error($result) || empty($result['verified'])) {
        $reason = (! is_wp_error($result) && ! empty($result['reason'])) ? (string) $result['reason'] : 'rejected';

        return rest_ensure_response(array('ok' => false, 'reason' => $reason));
    }

    if ($user) {
        return lets_payplus_account_sign_in($user, $request->get_param('redirect'));
    }

    if (! lets_payplus_account_quick_registration_allowed()) {
        // A store that only wants customers it already has. The answer is shaped
        // like a wrong code, because "correct code, no account" is the one
        // sentence this endpoint must never say out loud.
        return rest_ensure_response(array('ok' => false, 'reason' => 'rejected'));
    }

    // No WordPress user — but LETS may already KNOW this person: an imported
    // member has a year of subscriptions here and no WP account. Asking them to
    // "register" on a store that has been charging them is wrong twice, so their
    // account is opened FROM what LETS knows and they are signed straight in.
    $known = lets_payplus_account_provision_known_member($channel, $destination, $request->get_param('redirect'));
    if (null !== $known) {
        return $known;
    }

    // Verified, and nobody owns it. Hand back a ticket rather than the address:
    // the browser never gets to name the destination the next call will trust.
    return rest_ensure_response(array(
        'ok'       => true,
        'register' => true,
        'ticket'   => lets_payplus_account_issue_ticket($channel, $destination),
    ));
}

/**
 * Sign in a verified stranger LETS already knows, or null to fall back to the
 * quick-registration form.
 *
 * The SaaS is asked only AFTER the code proved the destination; it answers with
 * a name and an email, never more. Three outcomes:
 *   - LETS knows them + a WP user already holds their email → LINK: the proven
 *     phone is indexed onto that user (so next time the lookup finds them
 *     directly) and they are signed into it. Privileged accounts are refused
 *     outright — this door never opens into an admin.
 *   - LETS knows them + no WP user → their customer account is created from the
 *     LETS name and they are signed in. `created` rides the response so the
 *     panel can say what just happened.
 *   - LETS does not know them (or knows no usable name/email) → null; the
 *     registration form asks, exactly as before.
 *
 * @param  string  $channel      'email' | 'sms'
 * @param  string  $destination  the VERIFIED address the code answered from
 * @param  mixed   $redirect
 * @return WP_REST_Response|null
 */
function lets_payplus_account_provision_known_member($channel, $destination, $redirect)
{
    $identity = lets_payplus_signed_post('/api/woocommerce/account/identity', array(
        'channel'     => $channel,
        'destination' => $destination,
    ));

    if (is_wp_error($identity) || ! is_array($identity) || empty($identity['known'])) {
        return null;
    }

    // On the email channel the address is the one that answered the code; on SMS
    // the email is LETS's word for who owns the handset that answered.
    $email = 'email' === $channel
        ? sanitize_email($destination)
        : sanitize_email((string) ($identity['email'] ?? ''));

    if ('' === $email || ! is_email($email)) {
        return null;
    }

    $phone = 'sms' === $channel ? lets_payplus_account_digits($destination) : '';

    $existing = get_user_by('email', $email);
    if ($existing) {
        if (lets_payplus_account_is_privileged($existing)) {
            // Same wall as the direct lookup: a privileged account is not a
            // sign-in this endpoint performs, whatever LETS knows.
            return rest_ensure_response(array('ok' => false, 'reason' => 'rejected'));
        }

        if ('' !== $phone) {
            update_user_meta($existing->ID, LETS_ACCOUNT_PHONE_INDEX, $phone);
        }

        return lets_payplus_account_sign_in($existing, $redirect);
    }

    $first = lets_payplus_account_clean_name((string) ($identity['first_name'] ?? ''));
    $last = lets_payplus_account_clean_name((string) ($identity['last_name'] ?? ''));

    if ('' === $first || lets_payplus_account_name_too_long($first) || lets_payplus_account_name_too_long($last)) {
        // LETS knows them but not by a usable name — let them type it themselves.
        return null;
    }

    $user_id = lets_payplus_account_create_customer($email, $first, $last);
    if ($user_id <= 0) {
        return null;
    }

    lets_payplus_account_store_profile($user_id, $first, $last, $email, $phone);

    $user = get_userdata($user_id);
    if (! $user) {
        return null;
    }

    $response = lets_payplus_account_sign_in($user, $redirect);
    if ($response instanceof WP_REST_Response) {
        $data = $response->get_data();
        if (is_array($data) && ! empty($data['ok'])) {
            $data['created'] = true;
            $response->set_data($data);
        }
    }

    return $response;
}

/**
 * May a verified stranger open an account here?
 *
 * YES BY DEFAULT, and deliberately so: the whole point of the change is that a
 * shopper the store has never seen stops being told "a code is on its way" and
 * then left waiting for one that leads nowhere. A merchant who really does run a
 * closed store turns it off in one line:
 *
 *     add_filter('lets_payplus_account_quick_registration', '__return_false');
 *
 * It is deliberately NOT tied to WooCommerce's "allow customer registration"
 * checkbox. That box governs a form with a password on it; this is a different
 * door, opened only by a code the store itself sent, and a merchant who switched
 * passwordless sign-in ON has already said who may come in.
 *
 * @return bool
 */
function lets_payplus_account_quick_registration_allowed()
{
    /**
     * Filters whether a verified destination with no WordPress user may become
     * a customer.
     *
     * @param  bool  $allowed
     */
    return (bool) apply_filters('lets_payplus_account_quick_registration', true);
}

/**
 * Mint a single-use registration ticket for a destination LETS just verified.
 *
 * The DESTINATION stays on the server. What travels is an opaque random string,
 * and what is stored is its sha256 — so a ticket read out of a transient dump
 * cannot be replayed, and a ticket read out of the browser cannot be turned into
 * "register me as somebody else's phone number".
 *
 * @param  string  $channel
 * @param  string  $destination
 * @return string
 */
function lets_payplus_account_issue_ticket($channel, $destination)
{
    $ticket = wp_generate_password(48, false, false);

    set_transient(
        LETS_ACCOUNT_REG_TICKET_PREFIX . hash('sha256', $ticket),
        array(
            'channel'     => $channel,
            'destination' => $destination,
            'issued_at'   => time(),
        ),
        LETS_ACCOUNT_REG_TICKET_TTL
    );

    return $ticket;
}

/**
 * Create the customer behind a verified ticket, then sign them in.
 *
 * WHY THIS IS SAFE TO EXPOSE. Nothing here trusts the caller for identity. The
 * ticket is proof that a code sent to a specific destination came back correct,
 * and the destination it stands for is read from the SERVER's copy, never from
 * the request. The shopper may choose their name; on the verified channel they
 * may not choose their address.
 *
 * THE TICKET IS SPENT BEFORE THE USER IS CREATED, so two clicks on a slow
 * connection cannot become two accounts. Shape errors (an empty name, a
 * malformed email) are answered BEFORE the ticket is spent — a typo should cost
 * a correction, not the whole flow.
 */
function lets_payplus_account_rest_code_register(WP_REST_Request $request)
{
    if (! lets_payplus_account_quick_registration_allowed()) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'rejected'));
    }

    $ticket = (string) $request->get_param('ticket');
    $key = lets_payplus_account_ticket_key($ticket);
    if ('' === $key) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'ticket_expired'));
    }

    $first = lets_payplus_account_clean_name($request->get_param('first_name'));
    $last = lets_payplus_account_clean_name($request->get_param('last_name'));

    // The budget is keyed on the ticket rather than an address: the address is
    // not the caller's to name here, and the coarse per-IP bucket inside
    // spend_budget() is what actually caps a script.
    if (! lets_payplus_account_spend_budget('rg', $ticket)) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'rejected'));
    }

    $data = get_transient($key);
    if (! is_array($data) || empty($data['channel']) || empty($data['destination'])) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'ticket_expired'));
    }

    $channel = 'sms' === $data['channel'] ? 'sms' : 'email';
    $verified = (string) $data['destination'];

    if ('' === $first || '' === $last) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'name_required'));
    }
    if (lets_payplus_account_name_too_long($first) || lets_payplus_account_name_too_long($last)) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'name_long'));
    }

    // On the email channel the address is the one that answered the code; the
    // field the browser sent is decoration. On SMS it is the shopper's to type.
    $email = 'email' === $channel
        ? sanitize_email($verified)
        : sanitize_email((string) $request->get_param('email'));

    if ('' === $email || ! is_email($email)) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'email_invalid'));
    }

    // Same rule the other way round: an SMS sign-in owns the handset it proved.
    $phone = 'sms' === $channel
        ? $verified
        : sanitize_text_field((string) $request->get_param('phone'));
    $phone = lets_payplus_account_digits($phone);

    delete_transient($key);

    if (email_exists($email)) {
        // Not an enumeration leak: they just proved they hold the OTHER
        // destination, and the panel sends them back to sign in with this one.
        return rest_ensure_response(array('ok' => false, 'reason' => 'email_taken'));
    }

    $user_id = lets_payplus_account_create_customer($email, $first, $last);
    if ($user_id <= 0) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'rejected'));
    }

    lets_payplus_account_store_profile($user_id, $first, $last, $email, $phone);

    $user = get_userdata($user_id);
    if (! $user) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'rejected'));
    }

    return lets_payplus_account_sign_in($user, $request->get_param('redirect'));
}

/**
 * The transient key a ticket stands for, or '' when the string cannot be one.
 *
 * Shape-checked before it is hashed so a caller cannot use this endpoint to
 * write arbitrary lookups, and length-capped so a megabyte of "ticket" is not
 * hashed on every request.
 *
 * @param  string  $ticket
 * @return string
 */
function lets_payplus_account_ticket_key($ticket)
{
    $ticket = trim((string) $ticket);

    if (! preg_match('/^[A-Za-z0-9]{32,128}$/', $ticket)) {
        return '';
    }

    return LETS_ACCOUNT_REG_TICKET_PREFIX . hash('sha256', $ticket);
}

/**
 * One typed name, trimmed and stripped of markup.
 *
 * @param  mixed  $value
 * @return string
 */
function lets_payplus_account_clean_name($value)
{
    return trim(sanitize_text_field((string) $value));
}

/**
 * Longer than a name has any business being? Counted in CHARACTERS, not bytes —
 * "אביאד" is ten bytes and five letters, and a byte cap would refuse Hebrew
 * names at half the length it refuses English ones.
 *
 * @param  string  $name
 * @return bool
 */
function lets_payplus_account_name_too_long($name)
{
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

    return $length > LETS_ACCOUNT_NAME_MAX;
}

/**
 * Create the WooCommerce customer, or 0.
 *
 * WooCommerce's own factory where it exists: it picks a unique username from the
 * address, applies the store's registration rules and fires
 * `woocommerce_created_customer`, which is what sends the merchant's own new
 * account email. A password IS generated even though nobody will use it — an
 * account with an empty password is one `wp_signon` away from being anyone's.
 *
 * @param  string  $email
 * @param  string  $first
 * @param  string  $last
 * @return int
 */
function lets_payplus_account_create_customer($email, $first, $last)
{
    $password = wp_generate_password(32);

    if (function_exists('wc_create_new_customer')) {
        $created = wc_create_new_customer($email, '', $password, array(
            'first_name' => $first,
            'last_name'  => $last,
        ));

        return is_wp_error($created) ? 0 : (int) $created;
    }

    // WooCommerce inactive (the REST routes register regardless): a plain
    // customer, with a username derived from the address the same way.
    $created = wp_insert_user(array(
        'user_login'   => lets_payplus_account_username_for($email),
        'user_email'   => $email,
        'user_pass'    => $password,
        'first_name'   => $first,
        'last_name'    => $last,
        'display_name' => trim($first . ' ' . $last),
        'role'         => 'customer',
    ));

    return is_wp_error($created) ? 0 : (int) $created;
}

/**
 * A free username derived from an address.
 *
 * @param  string  $email
 * @return string
 */
function lets_payplus_account_username_for($email)
{
    $base = sanitize_user((string) substr($email, 0, (int) strpos($email, '@')), true);
    if ('' === $base) {
        $base = 'customer';
    }

    $candidate = $base;
    $suffix = 1;
    while (username_exists($candidate) && $suffix < 100) {
        $candidate = $base . $suffix;
        $suffix++;
    }

    return $candidate;
}

/**
 * Write the profile the rest of the store reads.
 *
 * Both halves matter: WordPress's own first/last name (which is what the
 * navigation and the greeting show) AND WooCommerce's billing fields (which is
 * what checkout prefills, and what the phone index mirrors). Writing only one of
 * them produces a customer whose name the shop knows and whose checkout is empty.
 *
 * @param  int  $user_id
 * @param  string  $first
 * @param  string  $last
 * @param  string  $email
 * @param  string  $phone  canonical digits, or ''
 */
function lets_payplus_account_store_profile($user_id, $first, $last, $email, $phone)
{
    update_user_meta($user_id, 'first_name', $first);
    update_user_meta($user_id, 'last_name', $last);
    update_user_meta($user_id, 'billing_first_name', $first);
    update_user_meta($user_id, 'billing_last_name', $last);
    update_user_meta($user_id, 'billing_email', $email);

    if ('' !== $phone) {
        // The index is kept by the meta watcher above, but it is called
        // explicitly too: a store where the watcher was unhooked would otherwise
        // create a customer who can never sign in by SMS again.
        update_user_meta($user_id, LETS_ACCOUNT_PHONE_META, $phone);
        lets_payplus_account_index_phone($user_id);
    }

    $display = trim($first . ' ' . $last);
    if ('' !== $display) {
        wp_update_user(array('ID' => $user_id, 'display_name' => $display));
    }
}

/**
 * Issue the session, once, for both passwordless routes.
 *
 * PRIVILEGED ACCOUNTS ARE REFUSED. A six-digit code on a phone, or a Google
 * account matching a stored address, is the right strength for a shopper's order
 * history and the wrong strength for the keys to the shop. If the resolved user
 * can edit the site, they sign in the way the site's own login page — and any
 * two-factor plugin the merchant installed — expects them to. The answer is
 * shaped like a wrong code so the panel has nothing new to leak.
 *
 * @param  WP_User  $user
 * @param  mixed  $redirect  where the shopper was when they signed in
 * @return WP_REST_Response
 */
function lets_payplus_account_sign_in($user, $redirect = null)
{
    if (lets_payplus_account_is_privileged($user)) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'rejected'));
    }

    // WooCommerce's own helper where it exists: it sets the auth cookie AND
    // re-associates the cart and customer session with the now-known shopper.
    // Signing in during checkout and losing the basket is not a sign-in.
    if (function_exists('wc_set_customer_auth_cookie')) {
        wc_set_customer_auth_cookie($user->ID);
    } else {
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
    }

    do_action('wp_login', $user->user_login, $user);

    return rest_ensure_response(array(
        'ok'       => true,
        'redirect' => lets_payplus_account_safe_redirect($redirect),
    ));
}

/**
 * Where to land after signing in: back where they were, if that is on this site.
 *
 * A shopper who signed in from the checkout's "returning customer" form wants to
 * carry on checking out, not to be deposited in their account page. Anything that
 * is not this site's own URL falls back to My Account — an open redirect on a
 * login endpoint is how a phishing page borrows a real domain.
 *
 * @param  mixed  $redirect
 * @return string
 */
function lets_payplus_account_safe_redirect($redirect)
{
    // Guarded: these routes register whether or not WooCommerce is active, and an
    // unguarded call would turn a deactivated plugin into a fatal on sign-in.
    $fallback = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '';
    $fallback = '' !== (string) $fallback ? $fallback : home_url('/');
    $candidate = is_string($redirect) ? trim($redirect) : '';

    if ('' === $candidate) {
        return $fallback;
    }

    $safe = wp_validate_redirect(esc_url_raw($candidate), '');

    return '' !== $safe ? $safe : $fallback;
}

/**
 * Sign in with a Google credential.
 *
 * Same trust split as the codes: GOOGLE attests to the email (we verify the ID
 * token against Google's tokeninfo endpoint, which checks the signature and the
 * expiry for us, and we check `aud` and `iss` ourselves), and WORDPRESS decides
 * which user that email belongs to and issues the session. Existing accounts
 * only — a personal area is for shoppers the store already knows, and minting
 * users from a login button is a store-policy decision this plugin must not
 * take on the merchant's behalf.
 *
 * "no_account" is not an enumeration leak: Google just proved the caller OWNS
 * the address, so the only list they can probe is their own inbox.
 */
function lets_payplus_account_rest_google(WP_REST_Request $request)
{
    $settings = lets_payplus_account_login_settings();
    $client_id = isset($settings['google_client_id']) ? trim((string) $settings['google_client_id']) : '';
    $credential = trim((string) $request->get_param('credential'));

    if ('' === $client_id || '' === $credential || strlen($credential) > 4096) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'rejected'));
    }

    // The same local budget as the codes: an unauthenticated endpoint must not
    // become a free proxy to Google's verifier. Keyed on the credential as well
    // as the IP, so a store behind a CDN — where every shopper shares one
    // REMOTE_ADDR — does not lock the whole country out of the Google button.
    if (! lets_payplus_account_spend_budget('gg', $credential)) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'rejected'));
    }

    $email = lets_payplus_account_google_email($credential, $client_id);
    if (null === $email) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'rejected'));
    }

    $user = get_user_by('email', $email);
    if (! $user) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'no_account'));
    }

    return lets_payplus_account_sign_in($user, $request->get_param('redirect'));
}

/**
 * The VERIFIED email inside a Google ID token, or null.
 *
 * tokeninfo validates the token's signature and expiry on Google's side; what is
 * left to us is that the token was minted for THIS shop's client id (`aud` — a
 * token from any other site's Google button must not open an account here), that
 * it was issued by Google (`iss`), and that Google itself marks the address
 * verified — an unverified Gmail alias is just a typed string with a costume.
 *
 * @param  string  $credential
 * @param  string  $client_id
 * @return string|null
 */
function lets_payplus_account_google_email($credential, $client_id)
{
    $response = wp_remote_get(
        'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential),
        array('timeout' => 10)
    );

    if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
        return null;
    }

    $claims = json_decode((string) wp_remote_retrieve_body($response), true);
    if (! is_array($claims)) {
        return null;
    }

    $aud = isset($claims['aud']) ? (string) $claims['aud'] : '';
    $iss = isset($claims['iss']) ? (string) $claims['iss'] : '';
    $email = isset($claims['email']) ? sanitize_email((string) $claims['email']) : '';
    $verified = isset($claims['email_verified']) ? $claims['email_verified'] : '';

    if (! hash_equals($client_id, $aud)) {
        return null;
    }
    if (! in_array($iss, array('accounts.google.com', 'https://accounts.google.com'), true)) {
        return null;
    }
    if ('' === $email || ! in_array($verified, array('true', true, 1, '1'), true)) {
        return null;
    }

    return $email;
}

/**
 * The WP user behind a typed address or phone, or null.
 *
 * The phone lookup is a meta query against WooCommerce's own billing_phone, and
 * it compares NORMALISED digits: shoppers type the number they gave at checkout
 * with different spacing, and a strict match would silently fail for them.
 *
 * @param  string  $channel
 * @param  string  $destination
 * @return WP_User|null
 */
function lets_payplus_account_find_user($channel, $destination)
{
    $destination = trim($destination);
    if ('' === $destination) {
        return null;
    }

    if ('email' === $channel) {
        $user = get_user_by('email', $destination);

        return $user ? $user : null;
    }

    $digits = lets_payplus_account_digits($destination);
    if ('' === $digits) {
        return null;
    }

    // ONE indexed lookup against our canonical mirror. This used to walk the first
    // 500 customers and compare each one in PHP, which meant customer 501 could
    // never sign in by SMS — and the panel said "code on its way" all the same.
    $matches = get_users(array(
        'meta_key'    => LETS_ACCOUNT_PHONE_INDEX, // phpcs:ignore WordPress.DB.SlowDBQuery
        'meta_value'  => $digits,                  // phpcs:ignore WordPress.DB.SlowDBQuery
        'number'      => 2,
        'fields'      => array('ID'),
        // Without this WP_User_Query adds SQL_CALC_FOUND_ROWS, which counts the
        // whole matching set and throws away the point of asking for two rows.
        'count_total' => false,
    ));

    // Two accounts on one handset is ambiguous, and guessing which shopper meant
    // which is not a guess a sign-in may make. Neither is signed in.
    if (1 === count($matches)) {
        $user = get_userdata($matches[0]->ID);

        return $user ? $user : null;
    }
    if (count($matches) > 1) {
        return null;
    }

    return lets_payplus_account_find_unindexed_user($digits);
}

/**
 * The safety net for a customer the backfill has not reached yet.
 *
 * Rather than scanning, we ask for the spellings a checkout actually produces —
 * each one an indexed equality on `billing_phone`. A hit is mirrored on the spot,
 * so this path runs at most once per customer and the store heals as people use it.
 *
 * @param  string  $digits  canonical, digits only
 * @return WP_User|null
 */
function lets_payplus_account_find_unindexed_user($digits)
{
    $clauses = array_map(
        static function ($spelling) {
            return array(
                'key'     => LETS_ACCOUNT_PHONE_META,
                'value'   => $spelling,
                'compare' => '=',
            );
        },
        lets_payplus_account_phone_spellings($digits)
    );

    $users = get_users(array(
        'number'      => 2,
        'fields'      => array('ID'),
        'count_total' => false,
        'meta_query'  => array_merge(array('relation' => 'OR'), $clauses), // phpcs:ignore WordPress.DB.SlowDBQuery
    ));

    if (1 !== count($users)) {
        return null;
    }

    $user = get_userdata($users[0]->ID);
    if (! $user) {
        return null;
    }

    lets_payplus_account_index_phone($user->ID);

    return $user;
}

/**
 * The handful of ways one Israeli mobile is written on a checkout form.
 *
 * Not every conceivable spacing — an exhaustive list is what the index is for.
 * These are the forms a phone keypad, a browser autofill and a copied contact
 * actually produce, and each is a single indexed comparison.
 *
 * @param  string  $digits  e.g. 0501234567
 * @return list<string>
 */
function lets_payplus_account_phone_spellings($digits)
{
    $spellings = array($digits);

    if (preg_match('/^0(\d{2})(\d{3})(\d{4})$/', $digits, $m)) {
        $local = '0' . $m[1];
        $intl = '972' . $m[1];

        $spellings[] = $local . '-' . $m[2] . $m[3];
        $spellings[] = $local . '-' . $m[2] . '-' . $m[3];
        $spellings[] = $local . ' ' . $m[2] . $m[3];
        $spellings[] = $local . ' ' . $m[2] . ' ' . $m[3];
        $spellings[] = '+' . $intl . $m[2] . $m[3];
        $spellings[] = '+' . $intl . '-' . $m[2] . '-' . $m[3];
        $spellings[] = '+' . $intl . ' ' . $m[2] . ' ' . $m[3];
        $spellings[] = $intl . $m[2] . $m[3];
        $spellings[] = '00' . $intl . $m[2] . $m[3];
    }

    return array_values(array_unique($spellings));
}

/**
 * Mirror one user's billing phone into the canonical index.
 *
 * @param  int  $user_id
 * @param  bool  $mark_empty  write an empty marker instead of removing the key
 *                            when there is no usable number. The backfill needs
 *                            this: it selects customers who have no index row,
 *                            so a customer whose phone cannot be normalised must
 *                            still come out of that set or the pass never ends.
 */
function lets_payplus_account_index_phone($user_id, $mark_empty = false)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return;
    }

    $digits = lets_payplus_account_digits((string) get_user_meta($user_id, LETS_ACCOUNT_PHONE_META, true));

    if ('' === $digits) {
        if ($mark_empty) {
            update_user_meta($user_id, LETS_ACCOUNT_PHONE_INDEX, '');

            return;
        }

        delete_user_meta($user_id, LETS_ACCOUNT_PHONE_INDEX);

        return;
    }

    update_user_meta($user_id, LETS_ACCOUNT_PHONE_INDEX, $digits);
}

/**
 * Keep the index honest. Every route by which a billing phone changes — the
 * address form, checkout, an admin edit, an import — ends in user meta, so that
 * is where we listen rather than trying to enumerate the routes.
 */
add_action('added_user_meta', 'lets_payplus_account_watch_phone_meta', 10, 3);
add_action('updated_user_meta', 'lets_payplus_account_watch_phone_meta', 10, 3);
add_action('deleted_user_meta', 'lets_payplus_account_watch_phone_meta', 10, 3);

function lets_payplus_account_watch_phone_meta($meta_id, $user_id, $meta_key)
{
    if (LETS_ACCOUNT_PHONE_META !== $meta_key) {
        return;
    }

    lets_payplus_account_index_phone($user_id);
}

/**
 * Backfill the index for customers who already existed. Runs in the background in
 * bounded batches, then stops scheduling itself — a store with 40,000 customers
 * must not pay for this in one request, and must not pay for it twice.
 */
add_action('init', 'lets_payplus_account_schedule_phone_backfill');

function lets_payplus_account_schedule_phone_backfill()
{
    if (LETS_ACCOUNT_PHONE_INDEX_VERSION === get_option(LETS_ACCOUNT_PHONE_INDEX_OPT)) {
        return;
    }

    if (! wp_next_scheduled(LETS_ACCOUNT_PHONE_INDEX_HOOK)) {
        wp_schedule_single_event(time() + 30, LETS_ACCOUNT_PHONE_INDEX_HOOK);
    }
}

add_action(LETS_ACCOUNT_PHONE_INDEX_HOOK, 'lets_payplus_account_backfill_phones');

function lets_payplus_account_backfill_phones()
{
    // NO CURSOR. The query asks for customers who have a phone and no index row,
    // and every pass gives each of them one — so the set shrinks by exactly what
    // was done and the next pass continues where this one stopped. An offset
    // cursor over the same set silently skips a whole batch the moment a customer
    // is deleted mid-backfill, and there is no second chance: the version marker
    // says the work is finished.
    $users = get_users(array(
        'number'      => LETS_ACCOUNT_PHONE_INDEX_BATCH,
        'orderby'     => 'ID',
        'order'       => 'ASC',
        'fields'      => array('ID'),
        'count_total' => false,
        'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
            'relation' => 'AND',
            array('key' => LETS_ACCOUNT_PHONE_META, 'compare' => 'EXISTS'),
            array('key' => LETS_ACCOUNT_PHONE_INDEX, 'compare' => 'NOT EXISTS'),
        ),
    ));

    foreach ($users as $user) {
        lets_payplus_account_index_phone($user->ID, true);
    }

    if (count($users) < LETS_ACCOUNT_PHONE_INDEX_BATCH) {
        update_option(LETS_ACCOUNT_PHONE_INDEX_OPT, LETS_ACCOUNT_PHONE_INDEX_VERSION, false);

        return;
    }

    wp_schedule_single_event(time() + 30, LETS_ACCOUNT_PHONE_INDEX_HOOK);
}

/**
 * Digits only, with an Israeli country code folded to the local 0-prefixed form.
 * Mirrors App\Support\PhoneNumber on the SaaS side — the two must agree, or a
 * code is delivered to a handset and then looked up under a different key.
 */
function lets_payplus_account_digits($phone)
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if (! is_string($digits)) {
        return '';
    }
    if (0 === strpos($digits, '00972')) {
        $digits = '0' . substr($digits, 5);
    } elseif (0 === strpos($digits, '972')) {
        $digits = '0' . substr($digits, 3);
    }

    return $digits;
}

/**
 * The caller's IP, for throttling only. REMOTE_ADDR and nothing else: an
 * X-Forwarded-For a client can set would make the per-IP bucket meaningless.
 */
function lets_payplus_account_client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
}

/**
 * Spend one unit of this browser's local budget. False when it is gone.
 *
 * KEYED ON IP **AND** DESTINATION, deliberately. REMOTE_ADDR is the only address
 * we can trust — a header the client sets is no limit at all — but on the many
 * stores that sit behind a CDN or a reverse proxy it is the PROXY's address, so
 * an IP-only bucket is one budget shared by every shopper in the country: the
 * ninth person to ask for a code that hour is silently refused. Adding the
 * destination gives each shopper their own budget while still capping how often
 * one address can be targeted from here.
 *
 * @param  string  $scope  which endpoint is spending
 * @param  string  $destination
 * @return bool
 */
function lets_payplus_account_spend_budget($scope, $destination)
{
    $ip = lets_payplus_account_client_ip();

    // TWO buckets, and both must have room. The fine one protects the SHOPPER —
    // it is what stops one address being targeted from this browser. The coarse
    // one protects the DATABASE: every call here runs a user lookup, so without a
    // per-address ceiling somebody could rotate destinations for free lookups
    // forever. Fine first, so an ordinary shopper is answered by the cheap check.
    $fine = 'lets_code_' . $scope . '_' . md5($ip . '|' . strtolower(trim($destination)));
    if (! lets_payplus_account_spend_window($fine, LETS_ACCOUNT_LOCAL_CODE_LIMIT)) {
        return false;
    }

    return lets_payplus_account_spend_window('lets_code_ip_' . md5($ip), LETS_ACCOUNT_IP_CODE_LIMIT);
}

/**
 * Take one unit out of a fixed hourly window. False when it is empty.
 *
 * @param  string  $bucket
 * @param  int  $limit
 * @return bool
 */
function lets_payplus_account_spend_window($bucket, $limit)
{
    $now = time();
    $window = get_transient($bucket);

    // The window carries its own end. Storing a bare counter and calling
    // set_transient with a fresh hour on every hit slides the window forward, so
    // a shopper who has spent their budget and keeps clicking pushes their own
    // release an hour further away with each click.
    if (! is_array($window) || empty($window['until']) || $window['until'] <= $now) {
        $window = array('spent' => 0, 'until' => $now + HOUR_IN_SECONDS);
    }

    if ($window['spent'] >= $limit) {
        return false;
    }

    $window['spent']++;
    set_transient($bucket, $window, max(1, $window['until'] - $now));

    return true;
}

/**
 * Is this an account that must not be signed in without a password?
 *
 * A shopper holds none of these. Anyone who can edit the site, its products or
 * its orders goes through WordPress's own login, where the merchant's two-factor
 * plugin still applies — a six-digit code must never open the back office.
 *
 * @param  WP_User  $user
 * @return bool
 */
function lets_payplus_account_is_privileged($user)
{
    foreach (LETS_ACCOUNT_PRIVILEGED_CAPS as $cap) {
        if (user_can($user, $cap)) {
            return true;
        }
    }

    return false;
}

/* -------------------------------------------------------------------------
 * 6. The sign-in screen
 *
 * THIS IS THE SIGN-IN, NOT A BOX BESIDE ONE. The panel used to render under
 * WooCommerce's username/password form, which put the shop's actual way in
 * second on its own login page: a shopper who has never had a password scrolled
 * past a form they could not use to reach the one they could. When the merchant
 * offers code sign-in, the panel REPLACES that form through the same
 * `woocommerce_locate_template` seam the rest of the area uses — and the
 * password form stays one quiet link away, because site staff, anyone with a
 * password manager, and any two-factor plugin the merchant installed all still
 * need it.
 *
 * THREE STEPS, ONE AT A TIME. Destination → code → (only when nobody owns the
 * address) name. Each step replaces the one before it rather than unfolding
 * below it: a form that grows as you answer it reads as a form you have not
 * finished, and the code step should look like the only thing on the screen,
 * because it is the only thing left to do.
 * ---------------------------------------------------------------------- */

/**
 * Does the LETS panel REPLACE WooCommerce's login form on this store?
 *
 * True only while there is something to replace it WITH — a merchant who never
 * switched code sign-in on (and configured no Google client) keeps WooCommerce's
 * own form untouched, because the alternative is a login page with no way in.
 *
 * @return bool
 */
function lets_payplus_account_login_takeover()
{
    if (null === lets_payplus_connection() || is_user_logged_in()) {
        return false;
    }

    $settings = lets_payplus_account_login_settings();

    return ! empty($settings['enabled']) || '' !== lets_payplus_account_google_client_id();
}

/** The merchant's Google client id, trimmed, or ''. */
function lets_payplus_account_google_client_id()
{
    $settings = lets_payplus_account_login_settings();

    return isset($settings['google_client_id']) ? trim((string) $settings['google_client_id']) : '';
}

/**
 * Has the panel already been drawn on this page?
 *
 * ONE sign-in screen per page. The takeover renders the panel and then renders
 * WooCommerce's own form (hidden) beneath it — and that form fires
 * `woocommerce_login_form_end`, which is where the panel is hooked. Without this
 * latch the screen would carry two copies of itself, the second one inside the
 * first's escape hatch.
 *
 * @param  bool  $mark  set the latch
 * @return bool
 */
function lets_payplus_account_login_drawn($mark = false)
{
    static $drawn = false;

    if ($mark) {
        $drawn = true;
    }

    return $drawn;
}

/**
 * The panel on any OTHER login form — checkout's "returning customer?", a theme
 * that calls woocommerce_login_form() itself. The my-account screen goes through
 * the template instead, and the latch above keeps the two from doubling up.
 */
add_action('woocommerce_login_form_end', 'lets_payplus_account_login_panel');

function lets_payplus_account_login_panel()
{
    if (lets_payplus_account_login_drawn()) {
        return;
    }

    if (null === lets_payplus_connection() || is_user_logged_in()) {
        return;
    }

    // Reading the settings can cost a round trip to LETS on a cache miss. On the
    // checkout page that would put the shopper's payment behind our uptime, so
    // there we render the panel only when the answer is already in hand. The
    // shopper keeps WooCommerce's own login either way, and the next account-page
    // view warms the cache.
    if (function_exists('is_checkout') && is_checkout() && ! lets_payplus_account_shell_config_cached()) {
        return;
    }

    echo lets_payplus_account_login_markup('inline'); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
}

/**
 * The sign-in card, as markup.
 *
 * @param  string  $context  'page' when it IS the screen (the template override),
 *                           'inline' when it sits inside somebody else's form.
 * @return string
 */
function lets_payplus_account_login_markup($context = 'inline')
{
    $settings = lets_payplus_account_login_settings();
    $codes = ! empty($settings['enabled']);
    $google = lets_payplus_account_google_client_id();

    if (! $codes && '' === $google) {
        return '';
    }

    lets_payplus_account_login_enqueue();
    lets_payplus_account_login_drawn(true);

    $he = lets_payplus_account_is_he();
    $page = 'page' === $context;

    // The channel the merchant offers decides what the shopper first sees: an
    // SMS-only shop must not open on an email field it will never send to.
    $channel_setting = isset($settings['channel']) ? (string) $settings['channel'] : 'email';
    $both = 'both' === $channel_setting;
    $initial = 'sms' === $channel_setting ? 'sms' : 'email';

    $title = $he ? 'כניסה לחשבון' : 'Sign in';
    if ($codes) {
        $subtitle = $he
            ? 'בלי סיסמה. נשלח לכם קוד חד-פעמי ותיכנסו איתו.'
            : 'No password. We send you a one-time code and you are in.';
    } else {
        $subtitle = $he
            ? 'התחברו עם חשבון Google שלכם.'
            : 'Continue with your Google account.';
    }

    $dest_label = 'sms' === $initial
        ? ($he ? 'מספר נייד' : 'Mobile number')
        : ($he ? 'כתובת מייל' : 'Email address');

    ob_start();
    ?>
    <div class="lets-acct la-auth"
         data-lets-login
         data-lets-context="<?php echo esc_attr($context); ?>"
         data-lets-initial-channel="<?php echo esc_attr($initial); ?>"
         <?php echo lets_payplus_account_shell_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed enum attributes, escaped at source.?><?php echo lets_payplus_account_dir_attribute(); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed enum attribute.?>>
        <div class="la-auth__card">

            <?php /* ---------- step 1 — who are you ---------- */ ?>
            <section class="la-auth__step" data-lets-step="dest">
                <h2 class="la-auth__title"><?php echo esc_html($title); ?></h2>
                <p class="la-auth__sub"><?php echo esc_html($subtitle); ?></p>

                <?php if ('' !== $google) : ?>
                    <div class="la-auth__google" data-lets-google
                         data-client-id="<?php echo esc_attr($google); ?>"></div>
                <?php endif; ?>

                <?php if ('' !== $google && $codes) : ?>
                    <div class="la-auth__or" aria-hidden="true">
                        <span class="la-auth__or-word"><?php echo esc_html($he ? 'או' : 'or'); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($codes) : ?>
                    <?php if ($both) : ?>
                        <div class="la-auth__switch" role="group"
                             aria-label="<?php echo esc_attr($he ? 'איך לשלוח את הקוד' : 'How to send the code'); ?>">
                            <button type="button"
                                    class="la-auth__seg<?php echo 'sms' === $initial ? ' is-active' : ''; ?>"
                                    data-lets-channel="sms" aria-pressed="<?php echo 'sms' === $initial ? 'true' : 'false'; ?>">
                                <?php echo esc_html($he ? 'סמס' : 'SMS'); ?>
                            </button>
                            <button type="button"
                                    class="la-auth__seg<?php echo 'email' === $initial ? ' is-active' : ''; ?>"
                                    data-lets-channel="email" aria-pressed="<?php echo 'email' === $initial ? 'true' : 'false'; ?>">
                                <?php echo esc_html($he ? 'מייל' : 'Email'); ?>
                            </button>
                        </div>
                    <?php endif; ?>

                    <label class="la-auth__field">
                        <span class="la-auth__label" data-lets-dest-label><?php echo esc_html($dest_label); ?></span>
                        <input type="text" class="la-auth__input<?php echo 'sms' === $initial ? ' la-ltr' : ''; ?>"
                               data-lets-dest
                               autocomplete="<?php echo 'sms' === $initial ? 'tel' : 'email'; ?>"
                               inputmode="<?php echo 'sms' === $initial ? 'tel' : 'email'; ?>">
                    </label>

                    <p class="la-auth__msg" data-lets-msg role="status" aria-live="polite"></p>

                    <button type="button" class="la-auth__submit" data-lets-send>
                        <?php echo esc_html($he ? 'שליחת קוד' : 'Send code'); ?>
                    </button>
                <?php else : ?>
                    <p class="la-auth__msg" data-lets-msg role="status" aria-live="polite"></p>
                <?php endif; ?>
            </section>

            <?php if ($codes) : ?>
                <?php /* ---------- step 2 — the code ---------- */ ?>
                <section class="la-auth__step" data-lets-step="code" hidden>
                    <h2 class="la-auth__title"><?php echo esc_html($he ? 'הזינו את הקוד' : 'Enter the code'); ?></h2>
                    <p class="la-auth__sub" data-lets-sent></p>

                    <label class="la-auth__field">
                        <span class="la-auth__label"><?php echo esc_html($he ? 'קוד בן 6 ספרות' : '6-digit code'); ?></span>
                        <input type="text" class="la-auth__input la-auth__input--code la-ltr"
                               data-lets-code-input
                               inputmode="numeric" autocomplete="one-time-code"
                               maxlength="6" pattern="[0-9]*"
                               aria-label="<?php echo esc_attr($he ? 'קוד אימות' : 'Verification code'); ?>">
                    </label>

                    <p class="la-auth__msg" data-lets-msg role="status" aria-live="polite"></p>

                    <button type="button" class="la-auth__submit" data-lets-verify>
                        <?php echo esc_html($he ? 'כניסה' : 'Sign in'); ?>
                    </button>

                    <div class="la-auth__links">
                        <button type="button" class="la-auth__link" data-lets-back>
                            <?php echo esc_html($he ? 'שינוי פרטים' : 'Change details'); ?>
                        </button>
                        <button type="button" class="la-auth__link" data-lets-resend>
                            <?php echo esc_html($he ? 'שליחה חוזרת' : 'Send again'); ?>
                        </button>
                    </div>
                </section>

                <?php /* ---------- step 3 — quick registration ---------- */ ?>
                <section class="la-auth__step" data-lets-step="register" hidden>
                    <h2 class="la-auth__title"><?php echo esc_html($he ? 'כמעט סיימנו' : 'Almost done'); ?></h2>
                    <p class="la-auth__sub">
                        <?php echo esc_html($he ? 'עוד שני פרטים ופותחים לכם חשבון.' : 'Two details and your account is ready.'); ?>
                    </p>

                    <div class="la-auth__row">
                        <label class="la-auth__field">
                            <span class="la-auth__label"><?php echo esc_html($he ? 'שם פרטי' : 'First name'); ?></span>
                            <input type="text" class="la-auth__input" data-lets-first
                                   autocomplete="given-name" maxlength="<?php echo esc_attr((string) LETS_ACCOUNT_NAME_MAX); ?>">
                        </label>
                        <label class="la-auth__field">
                            <span class="la-auth__label"><?php echo esc_html($he ? 'שם משפחה' : 'Last name'); ?></span>
                            <input type="text" class="la-auth__input" data-lets-last
                                   autocomplete="family-name" maxlength="<?php echo esc_attr((string) LETS_ACCOUNT_NAME_MAX); ?>">
                        </label>
                    </div>

                    <label class="la-auth__field">
                        <span class="la-auth__label" data-lets-email-label><?php echo esc_html($he ? 'כתובת מייל' : 'Email address'); ?></span>
                        <input type="email" class="la-auth__input" data-lets-email
                               autocomplete="email" inputmode="email">
                    </label>

                    <label class="la-auth__field">
                        <span class="la-auth__label" data-lets-phone-label><?php echo esc_html($he ? 'מספר נייד' : 'Mobile number'); ?></span>
                        <input type="text" class="la-auth__input la-ltr" data-lets-phone
                               autocomplete="tel" inputmode="tel">
                    </label>

                    <p class="la-auth__msg" data-lets-msg role="status" aria-live="polite"></p>

                    <button type="button" class="la-auth__submit" data-lets-create>
                        <?php echo esc_html($he ? 'יצירת חשבון וכניסה' : 'Create account and sign in'); ?>
                    </button>

                    <div class="la-auth__links">
                        <button type="button" class="la-auth__link" data-lets-back>
                            <?php echo esc_html($he ? 'חזרה' : 'Back'); ?>
                        </button>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($page) : ?>
                <?php /* The way in for everyone who HAS a password — staff included. */ ?>
                <p class="la-auth__alt">
                    <button type="button" class="la-auth__link" data-lets-classic
                            aria-controls="lets-login-classic" aria-expanded="false">
                        <?php echo esc_html($he ? 'כניסה עם סיסמה' : 'Sign in with a password'); ?>
                    </button>
                </p>
            <?php endif; ?>

        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * Register + enqueue the panel's stylesheet and behaviour, once per request.
 *
 * The login sheet is a LAYER on the personal area's, never a fork: the tokens it
 * paints with — the merchant's accent, the radius, the dark palette — are
 * declared on `.lets-acct` in lets-account.css and written over it by
 * wp_add_inline_style(). Ordering the dependency is what makes the sign-in card
 * the same product as the account page behind it.
 */
function lets_payplus_account_login_enqueue()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    wp_enqueue_style(
        'lets-payplus-account',
        LETS_PAYPLUS_URL . 'assets/css/lets-account.css',
        array(),
        LETS_PAYPLUS_VERSION
    );

    wp_enqueue_style(
        'lets-payplus-login',
        LETS_PAYPLUS_URL . 'assets/css/lets-login.css',
        array('lets-payplus-account'),
        LETS_PAYPLUS_VERSION
    );

    wp_register_script(
        'lets-payplus-login',
        LETS_PAYPLUS_URL . 'assets/js/lets-login.js',
        array(),
        LETS_PAYPLUS_VERSION,
        true
    );
    wp_localize_script('lets-payplus-login', 'LetsLoginCfg', lets_payplus_account_login_config());
    wp_enqueue_script('lets-payplus-login');
}

/**
 * The assets go out with the HEAD, not with the panel.
 *
 * The panel renders deep inside the body, and a stylesheet enqueued there lands
 * in the footer — the sign-in card would paint unstyled and then snap into
 * shape. So the page is recognised early and the assets are queued then; the
 * enqueue is latched, so the panel's own call is a no-op when this one ran.
 */
add_action('wp_enqueue_scripts', 'lets_payplus_account_login_assets', 20);

function lets_payplus_account_login_assets()
{
    if (is_user_logged_in() || null === lets_payplus_connection()) {
        return;
    }

    $ours = lets_payplus_account_is_ours();
    $on_checkout = function_exists('is_checkout') && is_checkout();

    // Some themes put [woocommerce_my_account] on a page of their own choosing,
    // where is_account_page() is false — the panel still renders there.
    $on_shortcode = ! $ours
        && function_exists('wc_post_content_has_shortcode')
        && wc_post_content_has_shortcode('woocommerce_my_account');

    if (! $ours && ! $on_checkout && ! $on_shortcode) {
        return;
    }

    // CHECKOUT NEVER WAITS ON US — the same rule the shell assets follow.
    if ($on_checkout && ! $ours && ! lets_payplus_account_shell_config_cached()) {
        return;
    }

    $settings = lets_payplus_account_login_settings();
    if (empty($settings['enabled']) && '' === lets_payplus_account_google_client_id()) {
        return;
    }

    lets_payplus_account_login_enqueue();
}

/**
 * Everything the panel's script needs, in one localized object.
 *
 * Bilingual the way the rest of this file is: the merchant picks the personal
 * area's language in the LETS dashboard, lets_payplus_account_is_he() reports
 * what it resolved to, and both catalogs are literals here — the plugin ships no
 * translation files and must not depend on the site's.
 *
 * @return array
 */
function lets_payplus_account_login_config()
{
    $he = lets_payplus_account_is_he();

    return array(
        'request'  => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/account/code/request')),
        'verify'   => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/account/code/verify')),
        'register' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/account/code/register')),
        'google'   => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/account/google')),
        'nonce'    => wp_create_nonce('wp_rest'),
        'locale'   => $he ? 'he' : 'en',
        'resend'   => (string) LETS_ACCOUNT_RESEND_SECONDS,
        'strings'  => array(
            'phone'          => $he ? 'מספר נייד' : 'Mobile number',
            'email'          => $he ? 'כתובת מייל' : 'Email address',
            'phone_optional' => $he ? 'מספר נייד (לא חובה)' : 'Mobile number (optional)',
            // The channel that answered the code is shown as read-only, and says
            // WHY it cannot be edited rather than leaving a greyed-out field.
            'phone_verified' => $he ? 'מספר נייד (מאומת)' : 'Mobile number (verified)',
            'email_verified' => $he ? 'כתובת מייל (מאומתת)' : 'Email address (verified)',
            'sending'        => $he ? 'שולחים…' : 'Sending…',
            'checking'       => $he ? 'בודקים…' : 'Checking…',
            'creating'       => $he ? 'פותחים חשבון…' : 'Creating your account…',
            // Shown when a verified member LETS already knows skips the
            // registration form: their account was opened for them.
            'provisioned'    => $he ? 'מייצרים את המשתמש שלך…' : 'Setting up your account…',
            // %s is the masked destination — substituted as a NODE, never as HTML.
            'sent_to'        => $he ? 'שלחנו קוד ל-%s' : 'We sent a code to %s',
            'resent'         => $he ? 'שלחנו קוד חדש.' : 'A new code is on its way.',
            'resend'         => $he ? 'שליחה חוזרת' : 'Send again',
            'resend_in'      => $he ? 'שליחה חוזרת בעוד %s' : 'Send again in %s',
            'bad_phone'      => $he ? 'המספר לא נראה תקין. בדקו ונסו שוב.' : 'That number does not look right. Check it and try again.',
            'bad_email'      => $he ? 'הכתובת לא נראית תקינה. בדקו ונסו שוב.' : 'That address does not look right. Check it and try again.',
            'code_short'     => $he ? 'הזינו את כל שש הספרות.' : 'Enter all six digits.',
            'rejected'       => $he ? 'הקוד שגוי.' : 'That code is not right.',
            'expired'        => $he ? 'הקוד פג. בקשו קוד חדש.' : 'That code has expired. Ask for a new one.',
            'exhausted'      => $he ? 'יותר מדי ניסיונות. בקשו קוד חדש.' : 'Too many attempts. Ask for a new code.',
            'name_required'  => $he ? 'שם פרטי ושם משפחה הם שדות חובה.' : 'First and last name are both needed.',
            'name_long'      => $he ? 'השם ארוך מדי.' : 'That name is too long.',
            'email_invalid'  => $he ? 'צריך כתובת מייל תקינה.' : 'A valid email address is needed.',
            'email_taken'    => $he ? 'לכתובת הזו כבר יש חשבון — היכנסו איתה.' : 'That address already has an account — sign in with it.',
            'email_taken_cta' => $he ? 'כניסה עם המייל' : 'Sign in with that email',
            'ticket_expired' => $he ? 'האימות פג. נתחיל מחדש.' : 'That verification expired. Let us start again.',
            'no_account'     => $he ? 'לא מצאנו חשבון עם הכתובת הזו.' : 'We could not find an account for that email.',
            'google_error'   => $he ? 'הכניסה עם Google לא הצליחה. נסו שוב.' : 'Google sign-in did not go through. Please try again.',
            'unreachable'    => $he ? 'משהו השתבש. רעננו את העמוד ונסו שוב.' : 'Something went wrong. Refresh the page and try again.',
            'password_show'  => $he ? 'כניסה עם סיסמה' : 'Sign in with a password',
            'password_hide'  => $he ? 'הסתרת הכניסה עם סיסמה' : 'Hide the password form',
        ),
    );
}

/**
 * Render WooCommerce's OWN login screen — the one our template replaced.
 *
 * WHY IT IS INCLUDED RATHER THAN REBUILT. The password form is not ours to
 * reimplement: it carries WooCommerce's nonce, its remember-me, its lost-password
 * link, the registration column, and every hook a security plugin uses to bolt a
 * captcha or a second factor onto the login. Copying that markup into our
 * template would freeze it at today's WooCommerce and quietly break the next one.
 *
 * WHY NOT wc_get_template(). It caches the located path for the request, so the
 * second call would hand back OUR file and recurse until the stack ran out. We
 * stand our filter down for the length of one wc_locate_template() instead, which
 * is exactly "what would WooCommerce have used", theme override included.
 */
function lets_payplus_account_render_classic_login()
{
    if (! function_exists('wc_locate_template')) {
        return;
    }

    remove_filter('woocommerce_locate_template', 'lets_payplus_account_locate_template', 99);
    $original = (string) wc_locate_template(LETS_ACCOUNT_LOGIN_TEMPLATE);
    add_filter('woocommerce_locate_template', 'lets_payplus_account_locate_template', 99, 2);

    if ('' === $original || ! file_exists($original)) {
        return;
    }

    // Belt and braces against the one thing that must never happen here.
    if (0 === strpos(wp_normalize_path($original), wp_normalize_path(LETS_ACCOUNT_TEMPLATE_DIR))) {
        return;
    }

    include $original;
}

/**
 * Whether the merchant offers code sign-in, and on which channels. Read from the
 * SaaS (it is a per-shop setting there) and cached, because this runs on a page
 * that must stay fast for shoppers who will just type their password.
 *
 * @return array{enabled: bool, channel: string}
 */
function lets_payplus_account_login_settings()
{
    $config = lets_payplus_account_shell_config();

    return isset($config['login'])
        ? (array) $config['login']
        : array('enabled' => false, 'channel' => 'email', 'google_client_id' => '');
}

/**
 * Does this shop allow a customer only ONE live subscription?
 *
 * Read from the cached shell, so the storefront can answer "is the rule even on?"
 * without a round trip. The per-customer question costs one, and is only ever
 * asked when this returns true.
 */
function lets_payplus_account_single_subscription_enabled()
{
    $config = lets_payplus_account_shell_config();

    return ! empty($config['purchase']['single_subscription']);
}

/**
 * "This shopper already has a subscription" — as a ready-to-show notice, or null.
 *
 * FAIL-OPEN on purpose. If LETS is unreachable, or the caller cannot be identified
 * at all, the shopper is allowed to buy. A rule that stops a sale is worth having;
 * a rule that stops a sale because a network call timed out is not — the merchant
 * would rather sell a second subscription than lose the first.
 *
 * @param  string  $email        Billing/account email, or ''.
 * @param  string  $customer_ref WC user id for a member, the email for a guest.
 * @return string|null           HTML notice, already translated, or null to allow.
 */
function lets_payplus_account_subscription_block_notice($email, $customer_ref)
{
    if (null === lets_payplus_connection() || ! lets_payplus_account_single_subscription_enabled()) {
        return null;
    }

    $customer_ref = trim((string) $customer_ref);
    if ('' === $customer_ref) {
        return null; // nobody to check — the checkout hook asks again with the email
    }

    $result = lets_payplus_signed_post('/api/woocommerce/account/subscription-eligibility', array(
        'customer_ref' => $customer_ref,
        'email'        => (string) $email,
        'locale'       => lets_payplus_account_site_locale(),
    ));

    if (is_wp_error($result) || empty($result['blocked'])) {
        return null;
    }

    $message = isset($result['message']) ? (string) $result['message'] : '';
    if ('' === $message) {
        return null;
    }

    $label = isset($result['link_label']) ? (string) $result['link_label'] : '';
    $url   = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url(LETS_ACCOUNT_ENDPOINT)
        : '';

    // The COPY is the server's (it holds the catalogs); the LINK is ours (only
    // WordPress knows where this store's account page lives).
    if ('' !== $label && '' !== $url) {
        return esc_html($message) . ' <a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    return esc_html($message);
}

/**
 * Hebrew UI?
 *
 * The merchant picks the personal area's language in the LETS dashboard, and the
 * payload reports back which language it was actually RESOLVED in — so the two
 * strings this plugin owns (the tab label and the sign-in panel) speak whatever
 * the rest of the page speaks. A shop on an older SaaS, or one that answered
 * "follow the store", falls back to WordPress's own language, which is what the
 * whole plugin used before.
 */
function lets_payplus_account_is_he()
{
    $config = lets_payplus_account_shell_config();
    $locale = isset($config['appearance']['locale']) ? (string) $config['appearance']['locale'] : '';

    if ('he' === $locale || 'en' === $locale) {
        return 'he' === $locale;
    }

    return function_exists('lets_payplus_is_he') ? lets_payplus_is_he() : false;
}

/** The WordPress site language, for the merchant's "follow the store" choice. */
function lets_payplus_account_site_locale()
{
    return function_exists('get_locale') ? (string) get_locale() : '';
}
