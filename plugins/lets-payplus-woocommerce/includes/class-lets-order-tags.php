<?php

/**
 * LETS — the "which orders did the app create?" column on WooCommerce → Orders.
 *
 * WooCommerce has no order tags: tags are a PRODUCT taxonomy in core, and under
 * HPOS an order is not a post to hang a taxonomy on. So LETS stamps the tag as
 * order meta (`lets_tags`) when it creates the order, and this file is what turns
 * that meta into something a merchant can SEE and FILTER — the same answer
 * Shopify's tags give on the other platform.
 *
 * Registers on whichever orders screen the store runs (HPOS or the classic post
 * table), mirroring class-lets-order-documents.php.
 *
 * @package LETS_PayPlus
 */

defined('ABSPATH') || exit;

// === CONSTANTS ===

/** Order meta holding the comma-separated tag line stamped by LETS. */
define('LETS_PAYPLUS_TAGS_META', 'lets_tags');

/** The umbrella tag: this order was created by LETS. Mirrors WooOrderTags::UMBRELLA. */
define('LETS_PAYPLUS_TAGS_UMBRELLA', 'LETS');

/** The query arg the filter dropdown submits. */
define('LETS_PAYPLUS_TAGS_FILTER_ARG', 'lets_tag');

/**
 * The kinds LETS stamps, mirroring WooOrderTags. Values are the raw tag; labels
 * are resolved at render time so the dropdown follows the admin's locale.
 */
function lets_payplus_tag_kinds()
{
    $he = function_exists('lets_payplus_is_he') && lets_payplus_is_he();

    return array(
        'installments' => $he ? 'תשלומים' : 'Installments',
        'recurring'    => $he ? 'מנוי' : 'Subscription',
        'upsell'       => $he ? 'אפסייל' : 'Upsell',
        'gift'         => $he ? 'מתנה' : 'Gift',
    );
}

/** The orders list screen id for this store (HPOS or classic). */
function lets_payplus_orders_screen()
{
    if (
        class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        && function_exists('wc_get_page_screen_id')
    ) {
        return wc_get_page_screen_id('shop-order');
    }

    return 'edit-shop_order';
}

/** The tag line on one order, as an array. Empty for an order LETS did not create. */
function lets_payplus_order_tags($order)
{
    $order = ($order instanceof WC_Order) ? $order : wc_get_order($order);
    if (! $order instanceof WC_Order) {
        return array();
    }

    $raw = (string) $order->get_meta(LETS_PAYPLUS_TAGS_META);
    if ('' === trim($raw)) {
        return array();
    }

    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

// ---------------------------------------------------------------------------
// The column
// ---------------------------------------------------------------------------

/**
 * HPOS and the classic table use different filter names for the same column set,
 * so both are registered; only one screen ever fires.
 */
add_filter('woocommerce_shop_order_list_table_columns', 'lets_payplus_tags_column', 20);
add_filter('manage_edit-shop_order_columns', 'lets_payplus_tags_column', 20);

function lets_payplus_tags_column($columns)
{
    $he = function_exists('lets_payplus_is_he') && lets_payplus_is_he();
    $columns['lets_tags'] = $he ? 'LETS' : 'LETS';

    return $columns;
}

add_action('woocommerce_shop_order_list_table_custom_column', 'lets_payplus_tags_column_hpos', 10, 2);
add_action('manage_shop_order_posts_custom_column', 'lets_payplus_tags_column_classic', 10, 2);

function lets_payplus_tags_column_hpos($column, $order)
{
    if ('lets_tags' !== $column) {
        return;
    }
    lets_payplus_render_tags_cell($order);
}

function lets_payplus_tags_column_classic($column, $post_id)
{
    if ('lets_tags' !== $column) {
        return;
    }
    lets_payplus_render_tags_cell(wc_get_order($post_id));
}

/** An order LETS did not create renders a dash, not an empty cell. */
function lets_payplus_render_tags_cell($order)
{
    $tags = lets_payplus_order_tags($order);

    if (array() === $tags) {
        echo '<span aria-hidden="true">—</span>';

        return;
    }

    $kinds = lets_payplus_tag_kinds();
    $out = array();
    foreach ($tags as $tag) {
        $label = isset($kinds[$tag]) ? $kinds[$tag] : $tag;
        $out[] = '<span class="lets-tag">' . esc_html($label) . '</span>';
    }

    echo wp_kses_post(implode(' ', $out));
}

add_action('admin_head', 'lets_payplus_tags_column_styles');

function lets_payplus_tags_column_styles()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (! $screen || lets_payplus_orders_screen() !== $screen->id) {
        return;
    }
    // Scoped to this screen; the admin list has no stylesheet of ours to extend.
    echo '<style>.lets-tag{display:inline-block;padding:1px 7px;margin:1px 2px 1px 0;border-radius:10px;'
        . 'background:#f1ecfd;color:#5f38bd;font-size:11px;line-height:18px;white-space:nowrap;}</style>';
}

// ---------------------------------------------------------------------------
// The filter
// ---------------------------------------------------------------------------

add_action('woocommerce_order_list_table_restrict_manage_orders', 'lets_payplus_tags_filter_dropdown');
add_action('restrict_manage_posts', 'lets_payplus_tags_filter_dropdown_classic');

function lets_payplus_tags_filter_dropdown()
{
    $he = function_exists('lets_payplus_is_he') && lets_payplus_is_he();
    $selected = isset($_GET[LETS_PAYPLUS_TAGS_FILTER_ARG])
        ? sanitize_text_field(wp_unslash($_GET[LETS_PAYPLUS_TAGS_FILTER_ARG]))
        : '';

    echo '<select name="' . esc_attr(LETS_PAYPLUS_TAGS_FILTER_ARG) . '">';
    echo '<option value="">' . esc_html($he ? 'כל ההזמנות' : 'All orders') . '</option>';
    echo '<option value="' . esc_attr(LETS_PAYPLUS_TAGS_UMBRELLA) . '"' . selected($selected, LETS_PAYPLUS_TAGS_UMBRELLA, false) . '>'
        . esc_html($he ? 'נוצרו על ידי LETS' : 'Created by LETS') . '</option>';

    foreach (lets_payplus_tag_kinds() as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>'
            . esc_html('— ' . $label) . '</option>';
    }
    echo '</select>';
}

function lets_payplus_tags_filter_dropdown_classic($post_type)
{
    if ('shop_order' !== $post_type) {
        return;
    }
    lets_payplus_tags_filter_dropdown();
}

/**
 * Apply the filter. A meta LIKE on a comma list, anchored with the separators so
 * "gift" cannot match a tag that merely contains it.
 */
add_filter('woocommerce_order_list_table_prepare_items_query_args', 'lets_payplus_tags_filter_query');

function lets_payplus_tags_filter_query($args)
{
    $tag = isset($_GET[LETS_PAYPLUS_TAGS_FILTER_ARG])
        ? sanitize_text_field(wp_unslash($_GET[LETS_PAYPLUS_TAGS_FILTER_ARG]))
        : '';

    if ('' === $tag) {
        return $args;
    }

    $args['meta_query'] = isset($args['meta_query']) ? $args['meta_query'] : array();
    $args['meta_query'][] = array(
        'key'     => LETS_PAYPLUS_TAGS_META,
        'value'   => $tag,
        'compare' => 'LIKE',
    );

    return $args;
}

add_action('pre_get_posts', 'lets_payplus_tags_filter_query_classic');

function lets_payplus_tags_filter_query_classic($query)
{
    if (! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get('post_type')) {
        return;
    }

    $tag = isset($_GET[LETS_PAYPLUS_TAGS_FILTER_ARG])
        ? sanitize_text_field(wp_unslash($_GET[LETS_PAYPLUS_TAGS_FILTER_ARG]))
        : '';

    if ('' === $tag) {
        return;
    }

    $meta = (array) $query->get('meta_query');
    $meta[] = array(
        'key'     => LETS_PAYPLUS_TAGS_META,
        'value'   => $tag,
        'compare' => 'LIKE',
    );
    $query->set('meta_query', $meta);
}
