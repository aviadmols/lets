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
 * `field.*`   — labels + the option sets they choose from
 * `warning.*` — merchant conditions that make an offer unshowable
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
        'subheading' => 'Offer a subscriber a different plan inside their own account area. One click, on the card they already saved.',
        'empty' => 'No offers yet.',
        'empty_help' => 'An offer points at one of your subscription templates. Its price and billing cycle come from that template, so they stay right when you change it.',
    ],

    'section' => [
        'basics' => 'Basics',
        'basics_help' => 'What this offer sells. The price and cycle are the template’s — this screen never sets a price.',
        'mode' => 'What happens when it is accepted',
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

        'template' => 'Offer this subscription',
        'template_help' => 'Only active subscription templates billed through PayPlus can be offered — a Shopify Payments plan has no saved card of ours to charge.',
        'template_empty' => 'This shop has no active PayPlus subscription template yet.',
        'quote' => 'The customer will pay',
        'quote_empty' => 'This template cannot be priced right now, so the offer will not be shown.',

        'mode' => 'Mode',
        'mode_option' => [
            'add' => 'Add — they keep their current subscription',
            'replace' => 'Replace — their current subscription ends',
        ],

        'timing' => 'When the replacement takes effect',
        'timing_option' => [
            'immediate' => 'Now — charge the saved card today',
            'period_end' => 'At the end of the current period — nothing is charged today',
        ],
        'timing_help' => 'At period end, the new subscription’s first charge falls on the day the old one would have renewed. There is no proration either way.',

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
        'button_text_help' => 'Leave blank for the default wording.',
        'https_only' => 'Must start with https://',

        'custom_html' => 'Custom HTML',
        'html_help' => 'Replaces the designed card entirely. Available tokens: {{button}} (required — this is where the button goes), {{price}}, {{product}}, {{cadence}}, {{heading}}. Scripts, iframes, forms, event handlers and the style attribute are removed before a customer ever sees it — style your block with CSS classes from your theme.',
        'html_preview' => 'Preview',
        'html_preview_help' => 'Exactly what is sent to the page, with sample values.',
    ],

    'warning' => [
        'charging_paused' => 'Live charging is switched off for this shop, so an offer that charges immediately is hidden from customers and would be refused. Offers set to “at the end of the current period” still work — they take no money today.',
        'one_subscription_add' => 'This shop allows one subscription per customer, so an “add” offer is hidden from customers. Use “replace”, or turn that rule off in Billing settings.',
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
    ],

    'form' => [
        'html_button_required' => 'Custom HTML must contain {{button}} exactly once, as text — that is where the accept button goes. Without it, nobody can take the offer.',
        'saved' => 'Offer saved.',
    ],

];
