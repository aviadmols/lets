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
        // The label follows the destination: `theme` opens the theme editor with
        // our block ready, `menus` opens Online Store → Navigation.
        'open_theme' => 'Add to my theme',
        'open_menus' => 'Open my store menus',
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

            // The thing merchants miss: the page is ALREADY live, on their own
            // domain, through the App Proxy. Nothing is installed, no theme is
            // edited — the only missing piece is something linking to it.
            'hint_shopify_ready' => 'This page is already live on your own domain — nothing to install and no theme changes. All that is missing is a link to it, and the "Loyalty club link" app block places one for you.',
            'step_shopify_1' => 'Easiest way: in the theme editor, add the "Loyalty club link" app block (under Apps) wherever the doorway should sit — it already points at the club page. Prefer a classic menu link? The next steps do it by hand.',
            'step_shopify_2' => 'Copy the address below. It is on your own domain, so it looks and behaves like any other page of your store.',
            'step_shopify_3' => 'Open Online Store → Navigation (the button below takes you straight there).',
            'step_shopify_4' => 'Choose the menu — usually Main menu — then Add menu item → paste the address → name it (for example "Rewards") → Add.',
            'step_shopify_5' => 'Save the menu. The link is live immediately.',
            'note_shopify' => 'Signed-in customers see their own balance and status automatically — Shopify tells us who they are. A signed-out visitor sees the join invitation, so the link is safe to show to everyone. You can also link to it from a button in your theme or from the customer-account page later.',

            'hint_woo_ready' => 'Put the shortcode on any page — the club renders inside it.',
            'step_woo_1' => 'Create a page in WordPress (for example "Members club").',
            'step_woo_2' => 'Paste the shortcode below into it and publish.',
        ],

        'account' => [
            'title' => 'Customer area',
            'description' => 'Subscriptions, what is coming and when, rewards and order history — inside My Account.',

            'hint_woo_auto' => 'The plugin adds this to My Account by itself. There is nothing to place — but you choose what appears in it.',
            'step_woo_1' => 'Make sure the plugin shows "connected" in WooCommerce → Settings → LETS.',
            'step_woo_2' => 'Open Settings → Customer area here to choose which sections show, reorder them, add side banners and set your colours.',
            'step_woo_3' => 'Visit My Account on your store — the new tab sits right under Dashboard.',
        ],
    ],
];
