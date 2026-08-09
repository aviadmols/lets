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
 *
 * @package LETS_PayPlus
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

/** Codes a single browser may request before we stop asking LETS at all. */
define('LETS_ACCOUNT_LOCAL_CODE_LIMIT', 8);

/** Our WooCommerce template overrides — the page shell and the bare dashboard. */
define('LETS_ACCOUNT_TEMPLATE_DIR', plugin_dir_path(LETS_PAYPLUS_FILE) . 'templates/');
define('LETS_ACCOUNT_TEMPLATES', 'myaccount/my-account.php|myaccount/dashboard.php');

/** Shop-wide shell config (appearance + sign-in), cached apart from any shopper. */
define('LETS_ACCOUNT_SHELL_CACHE', 'lets_account_shell_cfg');
define('LETS_ACCOUNT_SHELL_TTL', 300);

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
    if (get_option(LETS_ACCOUNT_REWRITE_OPT) !== LETS_ACCOUNT_REWRITE_VERSION) {
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

    $out = array();
    foreach ($items as $key => $label) {
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
            <?php echo lets_payplus_account_fallback($model); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
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
 * @param array $model
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
        'model'    => $model,
        // {action} is substituted by the renderer — one registered route, six verbs.
        'endpoint' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/account/act/{action}')),
        'nonce'    => wp_create_nonce('wp_rest'),
    ));

    wp_add_inline_script(
        'lets-payplus-account',
        'window.addEventListener("DOMContentLoaded",function(){'
        . 'var m=document.getElementById("lets-account");'
        . 'if(m&&window.LetsAccount&&window.LetsAccountData){'
        . 'window.LetsAccount.render(m,window.LetsAccountData.model,{'
        . 'endpoint:window.LetsAccountData.endpoint,nonce:window.LetsAccountData.nonce});}});'
    );
}

/**
 * No-JS fallback. Every value is escaped; nothing is actionable, because an
 * action needs the REST call the missing JavaScript would have made.
 *
 * @param array $model
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
            $next  = isset($sub['next_charge_at']) ? (string) $sub['next_charge_at'] : '';
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
    if (! lets_payplus_account_is_ours()) {
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
    // without it the shell simply keeps the width the theme handed it.
    wp_enqueue_script(
        'lets-payplus-account-shell',
        LETS_PAYPLUS_URL . 'assets/js/lets-account-shell.js',
        array(),
        LETS_PAYPLUS_VERSION,
        true
    );
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

    return $rules ? '.lets-shell,.la-nav{' . implode(';', $rules) . '}' : '';
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
        'data-theme' => array('light', 'dark', 'auto'),
        'data-density' => array('comfortable', 'compact'),
        'data-card' => array('outlined', 'flat', 'raised'),
        'data-font' => array('theme', 'heebo', 'system'),
    );
    $keys = array(
        'data-theme' => 'theme',
        'data-density' => 'density',
        'data-card' => 'card',
        'data-font' => 'font',
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
 * Appearance + sign-in settings for the shop, with no shopper in the request.
 *
 * Shop-wide and cached apart from any customer, so painting the nav on the
 * orders tab does not cost a per-user round trip — and so a signed-out visitor
 * on the login form is served from the same place.
 *
 * @return array{appearance: array, login: array{enabled: bool, channel: string}}
 */
function lets_payplus_account_shell_config()
{
    $cached = get_transient(LETS_ACCOUNT_SHELL_CACHE);
    if (is_array($cached)) {
        return $cached;
    }

    $config = array(
        'appearance' => array(),
        'login' => array('enabled' => false, 'channel' => 'email'),
    );

    $result = lets_payplus_signed_post('/api/woocommerce/account/bootstrap', array(
        'customer_ref' => '',
        'locale' => lets_payplus_account_site_locale(),
    ));

    if (! is_wp_error($result) && ! empty($result['account'])) {
        $account = (array) $result['account'];

        if (! empty($account['appearance'])) {
            $config['appearance'] = (array) $account['appearance'];
        }
        if (! empty($account['login'])) {
            $login = (array) $account['login'];
            $config['login'] = array(
                'enabled' => ! empty($login['enabled']),
                'channel' => isset($login['channel']) ? (string) $login['channel'] : 'email',
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
 * @param string $template
 * @param string $template_name
 * @return string
 */
add_filter('woocommerce_locate_template', 'lets_payplus_account_locate_template', 99, 2);

function lets_payplus_account_locate_template($template, $template_name)
{
    if (null === lets_payplus_connection()) {
        return $template;
    }

    if (! in_array($template_name, explode('|', LETS_ACCOUNT_TEMPLATES), true)) {
        return $template;
    }

    /**
     * Filters whether the plugin serves its own My Account templates.
     *
     * @param bool   $use           Whether to override the theme.
     * @param string $template_name The template being located.
     */
    if (! apply_filters('lets_payplus_account_own_template', true, $template_name)) {
        return $template;
    }

    $ours = LETS_ACCOUNT_TEMPLATE_DIR . $template_name;

    return file_exists($ours) ? $ours : $template;
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
    <nav class="woocommerce-MyAccount-navigation la-nav"<?php echo lets_payplus_account_shell_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed enum attributes, escaped at source. ?><?php echo lets_payplus_account_dir_attribute(); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed enum attribute. ?>
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
 * @param string $endpoint
 */
function lets_payplus_account_icon($endpoint)
{
    $paths = array(
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        LETS_ACCOUNT_ENDPOINT => '<path d="M20 12a8 8 0 1 1-2.34-5.66"/><path d="M20 4v4h-4"/>',
        'orders' => '<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4"/><path d="M9 12h7M9 16h5"/>',
        'downloads' => '<path d="M12 4v10"/><path d="m8 11 4 4 4-4"/><path d="M5 19h14"/>',
        'edit-address' => '<path d="M12 21s7-6.1 7-11a7 7 0 1 0-14 0c0 4.9 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/>',
        'payment-methods' => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19"/>',
        'edit-account' => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
        'subscriptions' => '<path d="M20 12a8 8 0 1 1-2.34-5.66"/><path d="M20 4v4h-4"/>',
        'customer-logout' => '<path d="M15 4h3.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H15"/><path d="M10 8 6 12l4 4"/><path d="M6 12h9"/>',
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
 * @param int $user_id
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
    $user    = get_userdata($user_id);
    $action  = sanitize_key((string) $request->get_param('action'));

    $result = lets_payplus_signed_post('/api/woocommerce/account/subscriptions/' . $action, array(
        'customer_ref' => (string) $user_id,
        'email'        => (string) ($user ? $user->user_email : ''),
        'name'         => (string) ($user ? $user->display_name : ''),
        'subscription' => sanitize_text_field((string) $request->get_param('subscription')),
        'date'         => sanitize_text_field((string) $request->get_param('date')),
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
 * @param mixed $rows
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
 * We resolve the typed address or phone to a WP user FIRST and only then call
 * LETS — so a code is never sent to an address with no account. The response is
 * the SAME either way: "if that matches an account, a code is on its way". An
 * endpoint that distinguishes the two is an account-enumeration oracle, and it
 * would leak the merchant's customer list to anyone with a browser.
 */
function lets_payplus_account_rest_code_request(WP_REST_Request $request)
{
    $channel     = 'sms' === $request->get_param('channel') ? 'sms' : 'email';
    $destination = sanitize_text_field((string) $request->get_param('destination'));

    // A local counter as well as the SaaS-side throttle: an unauthenticated
    // endpoint should not be able to spend the shop's SMS budget just because a
    // network hop is cheap.
    $bucket = 'lets_code_rq_' . md5(lets_payplus_account_client_ip());
    $spent  = (int) get_transient($bucket);
    if ($spent >= LETS_ACCOUNT_LOCAL_CODE_LIMIT) {
        return rest_ensure_response(array('ok' => true));
    }
    set_transient($bucket, $spent + 1, HOUR_IN_SECONDS);

    $user = lets_payplus_account_find_user($channel, $destination);
    if (! $user) {
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
 * Verify a code and, on success, log the WordPress user in.
 *
 * LETS attests only that the code matched the destination. WORDPRESS decides who
 * that destination belongs to and issues the session — which is why a leaked
 * shared secret cannot mint a login on its own.
 */
function lets_payplus_account_rest_code_verify(WP_REST_Request $request)
{
    $channel     = 'sms' === $request->get_param('channel') ? 'sms' : 'email';
    $destination = sanitize_text_field((string) $request->get_param('destination'));
    $code        = sanitize_text_field((string) $request->get_param('code'));

    $user = lets_payplus_account_find_user($channel, $destination);
    if (! $user) {
        // Same shape as a wrong code: a "no such account" here would undo the
        // enumeration protection the request endpoint just paid for.
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

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    do_action('wp_login', $user->user_login, $user);

    return rest_ensure_response(array(
        'ok'       => true,
        'redirect' => wc_get_page_permalink('myaccount'),
    ));
}

/**
 * The WP user behind a typed address or phone, or null.
 *
 * The phone lookup is a meta query against WooCommerce's own billing_phone, and
 * it compares NORMALISED digits: shoppers type the number they gave at checkout
 * with different spacing, and a strict match would silently fail for them.
 *
 * @param string $channel
 * @param string $destination
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

    $candidates = get_users(array(
        'meta_key'     => LETS_ACCOUNT_PHONE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
        'meta_compare' => 'EXISTS',
        'number'       => 500,
        'fields'       => array('ID'),
    ));

    foreach ($candidates as $candidate) {
        $stored = lets_payplus_account_digits((string) get_user_meta($candidate->ID, LETS_ACCOUNT_PHONE_META, true));
        if ('' !== $stored && $stored === $digits) {
            return get_userdata($candidate->ID);
        }
    }

    return null;
}

/** Digits only, with an Israeli country code folded to the local 0-prefixed form. */
function lets_payplus_account_digits($phone)
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if (! is_string($digits)) {
        return '';
    }
    if (0 === strpos($digits, '972')) {
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

/* -------------------------------------------------------------------------
 * 6. The sign-in panel on the login form
 * ---------------------------------------------------------------------- */

add_action('woocommerce_login_form_end', 'lets_payplus_account_login_panel');

function lets_payplus_account_login_panel()
{
    if (null === lets_payplus_connection() || is_user_logged_in()) {
        return;
    }

    $settings = lets_payplus_account_login_settings();
    if (empty($settings['enabled'])) {
        return;
    }

    $he  = lets_payplus_account_is_he();
    $sms = in_array($settings['channel'], array('sms', 'both'), true);

    wp_enqueue_style('lets-payplus-account', LETS_PAYPLUS_URL . 'assets/css/lets-account.css', array(), LETS_PAYPLUS_VERSION);

    ?>
    <div class="lets-acct" data-lets-login>
        <div class="la-card">
            <h3 class="la-section__title"><?php echo esc_html($he ? 'כניסה עם קוד' : 'Sign in with a code'); ?></h3>
            <p class="la-muted"><?php echo esc_html($he ? 'נשלח לכם קוד חד-פעמי.' : 'We will send you a one-time code.'); ?></p>

            <label class="la-field">
                <span class="la-field__label" data-lets-dest-label>
                    <?php echo esc_html($he ? 'כתובת מייל' : 'Email address'); ?>
                </span>
                <input type="text" class="la-input" data-lets-dest autocomplete="username">
            </label>

            <?php if ($sms) : ?>
                <p class="la-muted">
                    <button type="button" class="la-btn la-btn--quiet" data-lets-channel="sms">
                        <?php echo esc_html($he ? 'שליחה ב-SMS במקום' : 'Use SMS instead'); ?>
                    </button>
                </p>
            <?php endif; ?>

            <div class="la-editor__actions">
                <button type="button" class="la-btn la-btn--primary" data-lets-send>
                    <?php echo esc_html($he ? 'שליחת קוד' : 'Send code'); ?>
                </button>
            </div>

            <div class="la-editor" data-lets-code hidden>
                <label class="la-field">
                    <span class="la-field__label"><?php echo esc_html($he ? 'קוד אימות' : 'Verification code'); ?></span>
                    <input type="text" class="la-input la-ltr" inputmode="numeric" autocomplete="one-time-code" data-lets-code-input>
                </label>
                <div class="la-editor__actions">
                    <button type="button" class="la-btn la-btn--primary" data-lets-verify>
                        <?php echo esc_html($he ? 'כניסה' : 'Sign in'); ?>
                    </button>
                </div>
            </div>

            <p class="la-muted" data-lets-msg role="status" aria-live="polite"></p>
        </div>
    </div>
    <?php

    lets_payplus_account_login_script($he);
}

/**
 * The panel's behaviour. Inline rather than a file: it is ~40 lines, it only
 * exists on the login form, and it needs the localized strings anyway.
 *
 * @param bool $he
 */
function lets_payplus_account_login_script($he)
{
    $config = array(
        'request' => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/account/code/request')),
        'verify'  => esc_url_raw(rest_url(LETS_PAYPLUS_REST_NS . '/account/code/verify')),
        'nonce'   => wp_create_nonce('wp_rest'),
        'strings' => array(
            'sent'      => $he ? 'אם יש חשבון תואם, הקוד בדרך.' : 'If that matches an account, a code is on its way.',
            'rejected'  => $he ? 'הקוד שגוי.' : 'That code is not right.',
            'expired'   => $he ? 'הקוד פג. בקשו קוד חדש.' : 'That code has expired. Ask for a new one.',
            'exhausted' => $he ? 'יותר מדי ניסיונות. בקשו קוד חדש.' : 'Too many attempts. Ask for a new code.',
            'phone'     => $he ? 'מספר נייד' : 'Mobile number',
        ),
    );
    ?>
    <script>
    (function () {
        var cfg = <?php echo wp_json_encode($config); ?>;
        var root = document.querySelector('[data-lets-login]');
        if (!root) { return; }

        var channel = 'email';
        var dest = root.querySelector('[data-lets-dest]');
        var codeBox = root.querySelector('[data-lets-code]');
        var codeInput = root.querySelector('[data-lets-code-input]');
        var msg = root.querySelector('[data-lets-msg]');

        function post(url, body) {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json().catch(function () { return {}; }); });
        }

        var smsBtn = root.querySelector('[data-lets-channel="sms"]');
        if (smsBtn) {
            smsBtn.addEventListener('click', function () {
                channel = 'sms';
                root.querySelector('[data-lets-dest-label]').textContent = cfg.strings.phone;
                dest.setAttribute('inputmode', 'tel');
                smsBtn.disabled = true;
            });
        }

        root.querySelector('[data-lets-send]').addEventListener('click', function () {
            if (!dest.value) { return; }
            post(cfg.request, { channel: channel, destination: dest.value }).then(function () {
                // Always the same answer, whether or not the address exists.
                msg.textContent = cfg.strings.sent;
                codeBox.hidden = false;
                codeInput.focus();
            });
        });

        root.querySelector('[data-lets-verify]').addEventListener('click', function () {
            if (!codeInput.value) { return; }
            post(cfg.verify, { channel: channel, destination: dest.value, code: codeInput.value }).then(function (body) {
                if (body && body.ok && body.redirect) {
                    window.location.href = body.redirect;
                    return;
                }
                msg.textContent = cfg.strings[(body && body.reason) || 'rejected'] || cfg.strings.rejected;
            });
        });
    }());
    </script>
    <?php
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

    return isset($config['login']) ? (array) $config['login'] : array('enabled' => false, 'channel' => 'email');
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
