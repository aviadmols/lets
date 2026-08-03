<?php

/**
 * LETS — the members club on the storefront, via the [lets_loyalty] shortcode.
 *
 * WooCommerce has no theme app blocks and no App Proxy, so the merchant places
 * the club by pasting a shortcode into any page. Identity is established on the
 * SERVER: this file asks LETS (over the plugin's HMAC signer) for a short-lived
 * signed URL for the currently logged-in shopper, and renders that URL in an
 * iframe. The browser never learns the shared secret, and cannot edit who the
 * page is for — tampering breaks the signature and LETS refuses.
 *
 * A logged-out visitor gets a sign-in prompt instead of an iframe: a members
 * page with no member is either an empty page or, worse, someone else's.
 *
 * @package LETS_PayPlus
 */

defined('ABSPATH') || exit;

// === CONSTANTS ===

/** The shortcode the merchant pastes into a page. */
define('LETS_PAYPLUS_LOYALTY_SHORTCODE', 'lets_loyalty');

/** How long a minted page URL is cached per user (seconds). Shorter than its TTL. */
define('LETS_PAYPLUS_LOYALTY_CACHE_TTL', 15 * MINUTE_IN_SECONDS);

/** Default iframe height before the page reports its own (px). */
define('LETS_PAYPLUS_LOYALTY_MIN_HEIGHT', 900);

/** The query parameter a shared referral link carries. */
define('LETS_PAYPLUS_REFERRAL_PARAM', 'lets_ref');

add_shortcode(LETS_PAYPLUS_LOYALTY_SHORTCODE, 'lets_payplus_loyalty_shortcode');

/**
 * A visitor arriving on a member's referral link.
 *
 * WooCommerce has no "apply this coupon and redirect" URL of its own, so the
 * link carries ?lets_ref=CODE and we apply it to the session cart the moment WC
 * is ready. From there it behaves like any coupon the shopper typed: it shows in
 * the cart, discounts the order, and lands in the order's coupon_lines — which
 * is exactly what LETS reads to credit the member who sent them.
 *
 * Applying to an EMPTY cart is not possible in WooCommerce, so the code is held
 * in the session and applied as soon as there is something to discount.
 */
add_action('wp_loaded', 'lets_payplus_referral_capture', 20);

function lets_payplus_referral_capture()
{
    if (! function_exists('WC') || null === WC()->session) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public share link, not a form.
    if (isset($_GET[LETS_PAYPLUS_REFERRAL_PARAM])) {
        $code = sanitize_text_field(wp_unslash($_GET[LETS_PAYPLUS_REFERRAL_PARAM]));
        if ('' !== $code) {
            WC()->session->set('lets_referral_code', $code);
        }
    }

    $pending = WC()->session->get('lets_referral_code');
    if (! is_string($pending) || '' === $pending) {
        return;
    }

    // Nothing to discount yet — keep it for when the cart has contents.
    if (null === WC()->cart || WC()->cart->is_empty()) {
        return;
    }

    if (! WC()->cart->has_discount($pending)) {
        WC()->cart->apply_coupon($pending);
    }

    WC()->session->set('lets_referral_code', '');
}

/**
 * Render the club for whoever is looking.
 *
 * @return string
 */
function lets_payplus_loyalty_shortcode()
{
    if (! function_exists('lets_payplus_connection') || null === lets_payplus_connection()) {
        return ''; // store not connected — render nothing rather than an error
    }

    $he = function_exists('lets_payplus_is_he') && lets_payplus_is_he();

    if (! is_user_logged_in()) {
        return lets_payplus_loyalty_login_prompt($he);
    }

    $url = lets_payplus_loyalty_page_url();

    if ('' === $url) {
        // Either the club is off or LETS could not be reached. Both are "nothing
        // to show here", not a broken page for the shopper.
        return '';
    }

    return lets_payplus_loyalty_iframe($url);
}

/**
 * The signed members-page URL for the current user, cached briefly so a page
 * refresh does not re-sign on every view.
 *
 * @return string
 */
function lets_payplus_loyalty_page_url()
{
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return '';
    }

    $cache_key = 'lets_loyalty_url_' . $user_id;
    $cached = get_transient($cache_key);
    if (is_string($cached) && '' !== $cached) {
        return $cached;
    }

    $user = wp_get_current_user();

    $result = lets_payplus_signed_post('/api/woocommerce/loyalty/page-url', array(
        // The SAME customer reference the gateway records on the ledger, so the
        // member who earns the points is the member the page shows.
        'customer_ref' => (string) $user_id,
        'email'        => (string) $user->user_email,
        'name'         => (string) $user->display_name,
    ));

    if (is_wp_error($result) || empty($result['url'])) {
        return '';
    }

    $url = (string) $result['url'];
    set_transient($cache_key, $url, LETS_PAYPLUS_LOYALTY_CACHE_TTL);

    return $url;
}

/**
 * The iframe, plus the tiny listener that lets the page tell us its height (the
 * club is tall and varies with the number of tiers; a fixed height would either
 * clip it or leave a hole).
 *
 * @param string $url
 * @return string
 */
function lets_payplus_loyalty_iframe($url)
{
    $id = 'lets-loyalty-' . wp_rand(1000, 9999);

    ob_start();
    ?>
    <div class="lets-loyalty-wrap">
        <iframe
            id="<?php echo esc_attr($id); ?>"
            class="lets-loyalty-frame"
            src="<?php echo esc_url($url); ?>"
            title="<?php echo esc_attr__('Members club', 'lets-payplus'); ?>"
            loading="lazy"
            style="width:100%;border:0;min-height:<?php echo (int) LETS_PAYPLUS_LOYALTY_MIN_HEIGHT; ?>px"
        ></iframe>
    </div>
    <script>
    (function () {
        var frame = document.getElementById(<?php echo wp_json_encode($id); ?>);
        if (!frame) { return; }
        window.addEventListener('message', function (event) {
            if (!event.data || event.data.type !== 'lets-loyalty-height') { return; }
            var height = parseInt(event.data.height, 10);
            if (height > 0) { frame.style.height = height + 'px'; }
        });
    })();
    </script>
    <?php

    return (string) ob_get_clean();
}

/**
 * The signed-out state: an invitation, not an error.
 *
 * @param bool $he
 * @return string
 */
function lets_payplus_loyalty_login_prompt($he)
{
    $title = $he ? 'מועדון החברים' : 'Members club';
    $body  = $he ? 'התחברו לחשבון שלכם כדי לראות את הנקודות והסטטוס שלכם.' : 'Sign in to your account to see your points and status.';
    $cta   = $he ? 'התחברות' : 'Sign in';

    ob_start();
    ?>
    <div class="lets-loyalty-prompt">
        <h3><?php echo esc_html($title); ?></h3>
        <p><?php echo esc_html($body); ?></p>
        <a class="button" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">
            <?php echo esc_html($cta); ?>
        </a>
    </div>
    <?php

    return (string) ob_get_clean();
}
