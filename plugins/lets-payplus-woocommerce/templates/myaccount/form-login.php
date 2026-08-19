<?php

/**
 * LETS — the My Account SIGN-IN screen.
 *
 * WooCommerce's form-login.php, replaced. On a store that offers passwordless
 * sign-in the code panel is not an alternative to the username/password form —
 * it is the way in, and almost nobody on the store HAS a password to type. So
 * the panel is the screen, centred and alone, and WooCommerce's own form (with
 * the registration column it carries, and every hook a security plugin adds to
 * it) is one quiet link below it.
 *
 * NOTHING IS TAKEN AWAY. The classic form is WooCommerce's own file, included
 * verbatim — see lets_payplus_account_render_classic_login(). Staff, password
 * managers and two-factor plugins are unaffected; they just click once first.
 *
 * This file is served only while code sign-in is switched on for the shop — see
 * lets_payplus_account_locate_template() / lets_payplus_account_login_takeover().
 *
 * @package LETS_PayPlus
 * @version 9.5.0
 */

defined('ABSPATH') || exit;

$lets_panel = lets_payplus_account_login_markup('page');

// The settings changed under us between the filter and this line (the cache
// expired mid-request, LETS answered "off"): hand the whole screen back rather
// than render a login page with no way in.
if ('' === $lets_panel) {
    lets_payplus_account_render_classic_login();

    return;
}

/**
 * Notices are printed HERE, above the panel, and not where WooCommerce prints
 * them.
 *
 * `woocommerce_output_all_notices` is hooked to
 * `woocommerce_before_customer_login_form`, which fires INSIDE the classic form —
 * and the classic form starts hidden. Left alone, a shopper who typed the wrong
 * password would watch the page reload with the error folded away. Printing them
 * first also clears the queue, so the copy inside the classic form draws nothing.
 *
 * An error means somebody was already using the password form, so it opens with
 * the message it belongs to.
 */
$lets_had_error = function_exists('wc_notice_count') && wc_notice_count('error') > 0;
?>
<div class="la-auth-page">

    <?php
    if (function_exists('woocommerce_output_all_notices')) {
        woocommerce_output_all_notices();
    }

echo $lets_panel; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
?>

    <div class="la-auth__classic" id="lets-login-classic"<?php echo $lets_had_error ? '' : ' hidden'; ?>>
        <?php lets_payplus_account_render_classic_login(); ?>
    </div>

</div>
