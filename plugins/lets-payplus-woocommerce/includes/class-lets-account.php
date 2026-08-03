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

/**
 * Wrap the whole My Account screen so WooCommerce's OWN tabs — orders,
 * addresses, account details — pick up the same tokens. We restyle those; we do
 * not reimplement them. WooCommerce already handles address validation and
 * password changes correctly, and rebuilding that would be risk for no gain.
 */
add_filter('body_class', 'lets_payplus_account_body_class');

function lets_payplus_account_body_class($classes)
{
    if (function_exists('is_account_page') && is_account_page() && null !== lets_payplus_connection()) {
        $classes[] = 'lets-acct-shell';
        // The shell styles WooCommerce's markup, so the stylesheet has to be
        // present on every account tab, not only the ones we render.
        wp_enqueue_style(
            'lets-payplus-account',
            LETS_PAYPLUS_URL . 'assets/css/lets-account.css',
            array(),
            LETS_PAYPLUS_VERSION
        );
    }

    return $classes;
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
    $cached = get_transient('lets_account_login_cfg');
    if (is_array($cached)) {
        return $cached;
    }

    $result = lets_payplus_signed_post('/api/woocommerce/account/bootstrap', array('customer_ref' => ''));

    $settings = array('enabled' => false, 'channel' => 'email');
    if (! is_wp_error($result) && ! empty($result['account']['login'])) {
        $login = $result['account']['login'];
        $settings = array(
            'enabled' => ! empty($login['enabled']),
            'channel' => isset($login['channel']) ? (string) $login['channel'] : 'email',
        );
    }

    set_transient('lets_account_login_cfg', $settings, 5 * MINUTE_IN_SECONDS);

    return $settings;
}

/** Hebrew UI? Mirrors the helper the other files use. */
function lets_payplus_account_is_he()
{
    return function_exists('lets_payplus_is_he') ? lets_payplus_is_he() : false;
}
