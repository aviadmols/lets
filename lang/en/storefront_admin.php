<?php

/**
 * The ADMIN "Storefront" page — what the merchant can put on their store and how.
 * (The storefront.php files hold the customer-facing copy; this one is the
 * merchant's instructions.) Mirror every key in lang/he/storefront_admin.php.
 */
return [
    'title' => 'Storefront',
    'subtitle' => 'The parts of your store this app can power — and how to place each one.',
    'empty' => 'Connect your store to see the elements you can add.',

    'status' => [
        'ready' => 'Ready',
        'auto' => 'Automatic',
        'needs_setup' => 'Needs a step',
    ],

    'action' => [
        'add_to_theme' => 'Add to my theme',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'page_url_label' => 'Page address — link to it from your store menu',
        'shortcode_label' => 'Shortcode — paste it into any page',
        'snippet_heading' => 'Older theme? Paste this instead',
        'snippet_intro' => 'If your theme does not support app blocks, paste this line into your product template where the button should appear.',
    ],

    'element' => [
        'subscriptions' => [
            'title' => 'Subscription options',
            'description' => 'The subscribe-and-save choice on the product page: one-time vs subscription, cadence and the saving.',

            'hint_shopify_ready' => 'A subscription plan is published, so the block will show real options once it is on your product page.',
            'hint_shopify_needs_setup' => 'No subscription plan is active yet. Publish one under Products first, or the block will only offer a one-time purchase.',
            'step_shopify_1' => 'Click "Add to my theme" — the theme editor opens on your product page with our block ready.',
            'step_shopify_2' => 'Drag the block to where it should appear, usually just above the Add-to-cart button.',
            'step_shopify_3' => 'Save. Colours and wording are edited in the block settings on the right.',

            'hint_woo_auto' => 'The plugin renders this on every product that has an active subscription plan — nothing to place.',
            'hint_woo_needs_setup' => 'No subscription plan is active yet. Publish one under Products and the widget appears on that product.',
            'step_woo_1' => 'Publish a subscription plan for the product under Products.',
            'step_woo_2' => 'Open the product page in your store — the choice appears above the Add-to-cart button.',
        ],

        'deposit' => [
            'title' => 'Deposit & installments button',
            'description' => 'Lets a shopper reserve the product with a deposit and pay the rest in instalments.',

            'hint_shopify_ready' => 'Ready to place. The button opens the deposit calculator on your product page.',
            'step_shopify_1' => 'Click "Add to my theme" — the theme editor opens with the deposit block ready.',
            'step_shopify_2' => 'Drag it under the Add-to-cart button and set the label and colours.',
            'step_shopify_3' => 'Save, then open the product in your store to try it.',

            'hint_woo_auto' => 'The plugin renders this on every product page once the store is connected — nothing to place.',
            'step_woo_1' => 'Make sure the plugin shows "connected" in WooCommerce → Settings → LETS.',
            'step_woo_2' => 'Open any product page — the button sits under Add to cart.',
        ],

        'loyalty' => [
            'title' => 'Loyalty club page',
            'description' => 'The members page: tiers, points balance and the ways your customers earn.',

            'hint_shopify_ready' => 'The page is live on your store. Link to it so members can find it.',
            'step_shopify_1' => 'Copy the address below.',
            'step_shopify_2' => 'In Shopify go to Online Store → Navigation and add it as a menu item.',

            'hint_woo_ready' => 'Put the shortcode on any page — the club renders inside it.',
            'step_woo_1' => 'Create a page in WordPress (for example "Members club").',
            'step_woo_2' => 'Paste the shortcode below into it and publish.',
        ],
    ],
];
