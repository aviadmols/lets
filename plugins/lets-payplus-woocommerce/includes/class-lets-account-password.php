<?php

/**
 * LETS — hiding the "Password change" block on My Account → account details.
 *
 * WHY A STORE WOULD WANT THIS. On a shop whose shoppers sign in with a one-time
 * code, almost nobody HAS a password. Three password fields at the bottom of
 * their details are three fields that can only confuse: a shopper who fills them
 * in has invented a credential the sign-in screen never asks for, and one who
 * reads them wonders which password they forgot.
 *
 * HOW IT IS REMOVED. WooCommerce's `form-edit-account.php` prints the fieldset
 * inline — there is no "show the password block?" filter to answer. But the
 * template brackets it EXACTLY between two actions: `woocommerce_edit_account_form_fields`
 * fires immediately before it and `woocommerce_edit_account_form` immediately
 * after, with nothing else in between but the fieldset and a clearing div. So we
 * open an output buffer on the first and throw it away on the second. No copy of
 * WooCommerce's template to maintain, no regex over its markup, and a theme that
 * rearranged the form simply keeps its own.
 *
 * IT CANNOT SWALLOW THE PAGE. The buffer is only discarded when it is still the
 * one we opened, and a template that fires the opening hook without the closing
 * one gets it FLUSHED (not dropped) at the end of the form — the password block
 * then shows, which is the harmless failure.
 *
 * NOBODY IS LOCKED OUT. The password itself is untouched: WordPress's own
 * profile screen still changes it, and the sign-in screen still carries
 * WooCommerce's password form and its lost-password link one link down. This
 * hides a form; it does not remove a way in.
 */
defined('ABSPATH') || exit;

// === CONSTANTS ===

/**
 * The on/off option ("1"/"0"). Default OFF — an update must never quietly take a
 * control away from a store that was using it.
 */
define('LETS_PASSWORD_HIDE_OPT', 'lets_payplus_hide_password_fields');

/* -------------------------------------------------------------------------
 * 1. The toggle
 * ---------------------------------------------------------------------- */

function lets_payplus_password_hidden()
{
    return '1' === get_option(LETS_PASSWORD_HIDE_OPT, '0');
}

/* -------------------------------------------------------------------------
 * 2. The buffer — opened before the fieldset, dropped after it
 * ---------------------------------------------------------------------- */

/**
 * The nesting level our capture opened, or null when none is open. A static
 * holder rather than a global: one request, one buffer.
 *
 * @param  bool      $set    write the value instead of reading it
 * @param  int|null  $value  the level to remember
 * @return int|null
 */
function lets_payplus_password_level($set = false, $value = null)
{
    static $level = null;

    if ($set) {
        $level = $value;
    }

    return $level;
}

/** Start capturing, as LATE as possible — anything another plugin added is already out. */
add_action('woocommerce_edit_account_form_fields', 'lets_payplus_password_capture', PHP_INT_MAX);

function lets_payplus_password_capture()
{
    if (! lets_payplus_password_hidden() || null !== lets_payplus_password_level()) {
        return;
    }

    ob_start();
    lets_payplus_password_level(true, ob_get_level());
}

/**
 * Drop it, FIRST — before anything else hooked here (our own address block at
 * priority 10 included) has written a word into the buffer.
 */
add_action('woocommerce_edit_account_form', 'lets_payplus_password_discard', -PHP_INT_MAX);

function lets_payplus_password_discard()
{
    $level = lets_payplus_password_level();

    // Only ours, and only while it is still the innermost one: something that
    // opened a buffer and has not closed it is not ours to unwind.
    if (null === $level || ob_get_level() !== $level) {
        return;
    }

    ob_end_clean();
    lets_payplus_password_level(true, null);
}

/**
 * The safety net. A template that fired the opening hook but never the closing
 * one would otherwise leave our buffer holding the rest of the form. Flush it —
 * the password block appears, which is exactly the old behaviour.
 */
add_action('woocommerce_after_edit_account_form', 'lets_payplus_password_release', PHP_INT_MAX);
add_action('shutdown', 'lets_payplus_password_release', -PHP_INT_MAX);

function lets_payplus_password_release()
{
    $level = lets_payplus_password_level();

    if (null === $level || ob_get_level() !== $level) {
        return;
    }

    ob_end_flush();
    lets_payplus_password_level(true, null);
}
