<?php

/**
 * LETS — the My Account dashboard.
 *
 * WooCommerce's dashboard is two sentences of prose and three links: "Hello
 * Dana (not Dana? Log out)" and "from your account dashboard you can view your
 * recent orders…". Every one of those links is now a labelled item in the
 * navigation beside it, and the personal area below answers the question the
 * shopper actually came with. So the prose is dropped and only the hooks are
 * kept — including the two deprecated ones, because plugins still use them.
 *
 * This file is served only while no theme override exists — see
 * lets_payplus_account_locate_template().
 *
 * @package LETS_PayPlus
 * @version 9.5.0
 */

defined('ABSPATH') || exit;

/**
 * My Account dashboard — where lets_payplus_account_render_dashboard() draws
 * the shopper's subscriptions, benefits and rewards.
 *
 * @since 2.6.0
 */
do_action('woocommerce_account_dashboard');

/**
 * Deprecated woocommerce_before_my_account action.
 *
 * @deprecated 2.6.0
 */
do_action('woocommerce_before_my_account');

/**
 * Deprecated woocommerce_after_my_account action.
 *
 * @deprecated 2.6.0
 */
do_action('woocommerce_after_my_account');
