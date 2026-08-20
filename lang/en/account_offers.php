<?php

/*
 * Account offers — the MERCHANT-facing screen (Filament resource).
 *
 * Shopper-facing copy for the same feature lives in lang/*\/account.php under
 * `ui.offer_*` and `result.accept_offer*`, because that copy is resolved into the
 * bootstrap payload in the SHOPPER's language while this file is read in the
 * merchant's. Two audiences, two files; nothing is shared between them.
 *
 * The Hebrew mirror (lang/he/account_offers.php) is key-for-key identical.
 *
 * `nav.*`     — where the screen sits
 * `model.*`   — resource labels
 * `section.*` — form sections, in the order the form draws them
 * `field.*`   — labels + the option sets they choose from. The `*_option` groups
 *               are keyed by the CANONICAL column values (AccountOffer /
 *               AccountOfferTarget constants); the `*_short` groups are the same
 *               values in table-column wording.
 * `warning.*` — merchant conditions that make a target unshowable
 * `stat.*`    — the counts beside a saved offer
 * `table.*`   — list columns
 * `form.*`    — validation messages
 */

return [

    'nav' => [
        'label' => 'Account offers',
    ],

    'model' => [
        'label' => 'Account offer',
        'plural' => 'Account offers',
        'create' => 'New offer',
        'edit' => 'Edit offer',
        'subheading' => 'Offer a subscriber something else inside their own account area — a different plan, an extra product, or several to choose from. One click, on the card they already saved.',
        'empty' => 'No offers yet.',
        'empty_help' => 'An offer is one promotion that can sell several things. Each one points at a subscription template of yours or at a product in your catalog, so its price and billing cycle stay right when you change them there.',
    ],

    'section' => [
        'basics' => 'Basics',
        'basics_help' => 'What you call this offer, and whether it is switched on.',
        'targets' => 'What it sells',
        'targets_help' => 'One row per choice the customer gets. Add a second to put two buttons on one card — “switch to monthly” beside “add the mug”. This screen never sets a price: it comes from the template or the catalog.',
        'audience' => 'Who sees it',
        'audience_help' => 'Leave a filter empty to mean “anyone”. All the filters that are set must match.',
        'schedule' => 'When it runs',
        'placement' => 'Where it appears',
        'design' => 'Design',
        'design_help' => 'Leave these blank to use the product’s own name and image.',
        'html' => 'Custom HTML',
        'stats' => 'Reach',
    ],

    'field' => [
        'name' => 'Internal name',
        'name_help' => 'Only you see this. It shows up in the timeline when somebody accepts.',
        'status' => 'Status',
        'status_option' => [
            'draft' => 'Draft',
            'active' => 'Active',
        ],

        'add_target' => 'Add something to sell',
        'kind' => 'What is this?',
        'kind_option' => [
            'subscription' => 'A subscription — from one of your templates',
            'one_time' => 'A one-time product — from your catalog',
        ],

        'template' => 'Offer this subscription',
        'template_help' => 'Only active subscription templates billed through PayPlus can be offered — a Shopify Payments plan has no saved card of ours to charge.',
        'template_empty' => 'Pick a subscription template to see what the customer will pay.',
        'quote' => 'The customer will pay',
        'quote_empty' => 'This cannot be priced right now, so it will not be shown.',

        'product' => 'Offer this product',
        'product_help' => 'A plain product from your catalog. It is charged once, at the catalog price — there is no template and no billing cycle.',
        'product_empty' => 'Pick a product to see what the customer will pay.',
        'variant' => 'Variant',
        'variant_help' => 'Leave empty to use the product’s default variant.',
        'quantity' => 'Quantity',
        'quantity_times' => '×:count',

        'mode' => 'Mode',
        'mode_option' => [
            'add' => 'Add — they keep their current subscription',
            'replace' => 'Replace — their current subscription ends',
        ],
        'mode_short' => [
            'add' => 'Add',
            'replace' => 'Replace',
        ],

        'timing' => 'When the replacement takes effect',
        'timing_option' => [
            'immediate' => 'Now — charge the saved card today',
            'period_end' => 'At the end of the current period — nothing is charged today',
        ],
        'timing_short' => [
            'immediate' => 'now',
            'period_end' => 'at period end',
        ],
        'timing_help' => 'At period end, the new subscription’s first charge falls on the day the old one would have renewed. There is no proration either way.',

        'fulfilment' => 'How it reaches the customer',
        'fulfilment_option' => [
            'immediate' => 'Buy it now — charge the saved card and create a paid order',
            'next_order' => 'Add it to their next order — nothing is charged today',
        ],
        'fulfilment_short' => [
            'immediate' => 'Buy now',
            'next_order' => 'Next order',
        ],
        'fulfilment_help' => '“Next order” takes no money today: the line rides along on the subscription’s next charge, so it keeps working while live charging is off.',

        'token_key' => 'Name for the button token',
        'token_key_help' => 'Optional, lower-case letters, digits and underscores. Name it “upgrade” and you can place its button anywhere in your custom HTML with {{button_upgrade}}.',
        'target_button_text' => 'Button text',
        'target_button_text_help' => 'For this row only. Leave blank for the default wording.',

        'plan_kinds' => 'Subscription type',
        'frequencies' => 'Current billing cycle',
        'frequencies_help' => 'The cycle a subscriber is on TODAY — this is how you reach “yearly members only”.',
        'statuses' => 'Subscription status',
        'statuses_help' => 'Active and paused by default. A paused subscriber is still a subscriber.',
        'products' => 'Products',
        'products_help' => 'Only subscribers whose subscription is for one of these products.',

        'starts_at' => 'Starts',
        'ends_at' => 'Ends',
        'schedule_help' => 'Leave both empty to run until you switch it off.',

        'placement' => 'Placement',
        'placement_option' => [
            'top' => 'Top of the page — full width, one card per offer',
            'rail' => 'Side column — one card per offer',
            'plan' => 'Under the matching subscription — one card per subscription',
        ],
        'priority' => 'Order',
        'priority_help' => 'Lower shows first.',

        'heading' => 'Heading',
        'subtext' => 'Subtext',
        'image_url' => 'Image URL',
        'button_text' => 'Button text',
        'button_text_help' => 'Leave blank for the default wording. A row with its own button text overrides this.',
        'https_only' => 'Must start with https://',

        'custom_html' => 'Custom HTML',
        'html_help' => 'Replaces the designed card entirely. Available tokens: {{button}} (required — the button for the first thing this offer sells), {{button_2}} / {{button_<your own name>}} for a specific one, and {{price}}, {{product}}, {{cadence}}, {{heading}}, which describe the first one. Scripts, iframes, forms, event handlers and the style attribute are removed before a customer ever sees it — style your block with CSS classes from your theme.',
        'html_preview' => 'Preview',
        'html_preview_help' => 'Exactly what is sent to the page, with sample values.',
    ],

    'warning' => [
        'charging_paused' => 'Live charging is switched off for this shop, so anything here that takes money on the click is hidden from customers and would be refused. “At the end of the current period” and “add it to their next order” still work — they take no money today.',
        'one_subscription_add' => 'This shop allows one subscription per customer, so an “add” subscription is hidden from customers. Use “replace”, offer a one-time product instead, or turn that rule off in Billing settings.',
    ],

    'stat' => [
        'eligible_now' => 'Subscribers who would see it now',
        'eligible_now_help' => 'Matching the audience filters, with a working saved card.',
        'accepted' => 'Accepted',
        'past_renewal' => 'Of those, with a renewal date already behind them',
        'past_renewal_help' => 'For an offer that starts at the end of the current period, these are charged as soon as charging is live rather than on a future date.',
        'waiting_live' => 'Subscriptions from this offer waiting for live charging',
    ],

    'table' => [
        'name' => 'Offer',
        'status' => 'Status',
        'target' => 'Offers',
        'mode' => 'Mode',
        'placement' => 'Placement',
        'eligible' => 'Eligible now',
        'accepted' => 'Accepted',
        'last_accepted_at' => 'Last accepted',
        'no_targets' => 'Nothing to sell yet',
    ],

    'form' => [
        'html_button_required' => 'Custom HTML must contain at least one button token, as text — that is where a button goes. Use {{button}} for the first thing this offer sells, or {{button_2}}, {{button_<product id>}} or {{button_<your own name>}} to place a button for a specific one. Without one, nobody can take the offer.',
        'html_button_unknown' => ':token does not match anything this offer sells. Check the spelling, or add the target it names.',
        'token_key_invalid' => 'Use lower-case letters, digits and underscores only.',
        'token_key_duplicate' => 'Another row already uses this name. Two buttons answering to one token would charge for the wrong thing.',
        'saved' => 'Offer saved.',
    ],

];
