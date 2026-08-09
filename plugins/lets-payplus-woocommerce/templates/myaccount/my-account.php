<?php

/**
 * LETS — the My Account page.
 *
 * WooCommerce's own template with ONE addition: the whole screen is wrapped in
 * `.lets-shell`, which owns the page's grid (navigation + content) and its
 * width. Both actions and both WooCommerce classes are kept exactly where they
 * were, so themes and plugins that hook or style them keep working.
 *
 * This file is served only while no theme override exists — see
 * lets_payplus_account_locate_template().
 *
 * @package LETS_PayPlus
 * @version 9.5.0
 */

defined('ABSPATH') || exit;

?>
<div class="lets-shell"<?php echo lets_payplus_account_shell_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed enum attributes, escaped at source. ?>>

    <?php
    /**
     * My Account navigation.
     *
     * @since 2.6.0
     */
    do_action('woocommerce_account_navigation');
    ?>

    <div class="woocommerce-MyAccount-content">
        <div class="woocommerce-MyAccount-content-wrapper">
            <?php
            /**
             * My Account content.
             *
             * @since 2.6.0
             */
            do_action('woocommerce_account_content');
            ?>
        </div>
    </div>

</div>
