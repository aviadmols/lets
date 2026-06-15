<?php

// Post-purchase / thank-you-page upsell strings (Phase 6). Storefront-facing +
// admin (Post-Purchase Offers hub + Flow Builder). Mirror EVERY key 1:1 in
// lang/he/upsell.php.
return [
    // Widget headline + default copy (used when an offer leaves a field blank).
    'widget_eyebrow' => 'One-time offer',
    'default_headline' => 'Add this to your order',
    'default_subcopy' => 'A special add-on, just for you — added to the order you just placed.',
    'offer_default_title' => 'Special offer',

    // Pricing
    'price_now' => 'Now :price',
    'was_price' => 'was :price',
    'you_save' => 'You save :amount',

    // CTAs
    'accept_cta' => 'Add to my order',
    'decline_cta' => 'No thanks',

    // Consent disclosure — REQUIRED. States what is charged, how, and that it
    // uses the already-saved payment method (no new card entry).
    'consent_disclosure' => 'By clicking "Add to my order", :amount will be charged to the payment method you just used — no need to re-enter your card. This is a one-time charge.',
    'no_card_reentry' => 'Charged to your saved payment method. No card re-entry.',

    // Result states
    'success_title' => 'Added to your order',
    'success_body' => 'Thank you! :amount was charged to your saved payment method and added to your order.',
    'declined_title' => 'No problem',
    'declined_body' => 'No changes were made to your order.',
    'next_offer_cta' => 'See one more offer',
    'failed_title' => 'We could not complete that',
    'failed_body' => 'Your saved payment method could not be charged. Your original order is unaffected.',
    'no_consent_title' => 'We could not add that',
    'no_consent_body' => 'We do not have permission to charge your saved payment method for this offer. Your original order is unaffected.',
    'done' => 'You are all set.',

    // Funnel event-type labels (Activity tab badges).
    'event' => [
        'impression' => 'Impression',
        'accepted' => 'Accepted',
        'declined' => 'Declined',
        'charge_succeeded' => 'Charge succeeded',
        'charge_failed' => 'Charge failed',
    ],

    // ===================================================================
    // Admin — the Post-Purchase Offers hub + Flow Builder.
    // ===================================================================
    'admin' => [
        // Legacy data-contract labels (kept for compatibility).
        'impressions' => 'Impressions',
        'accepted' => 'Accepted',
        'declined' => 'Declined',
        'conversion_rate' => 'Conversion rate',
        'charge_success_rate' => 'Charge success rate',
        'total_revenue' => 'Upsell revenue',
        'aov_uplift' => 'AOV uplift',

        'title' => 'Post-Purchase Offers',
        'badge_new' => 'New',

        'tab' => [
            'overview' => 'Overview',
            'performance' => 'Performance',
            'activity' => 'Activity',
            'settings' => 'Settings',
        ],

        'overview' => [
            'coming_soon' => 'Analytics for post-purchase upsell swaps are coming soon. Revenue, impressions and conversion below reflect your token-based one-click offers.',
        ],

        'kpi' => [
            'revenue' => 'Total post-purchase revenue',
            'impressions' => 'Impressions',
            'conversion' => 'Post-purchase conversion rate',
            'orders' => '# of post-purchase orders',
            'last_30_days' => 'Last 30 days',
        ],

        'flows' => [
            'title' => 'Your flows',
            'create' => 'Create new',
            'active' => 'Active',
            'inactive' => 'Inactive',
            'reorder' => 'Reorder priority',
            'offer_count' => '{0}No offers|{1}:count offer|[2,*]:count offers',
            'col' => [
                'priority' => 'Priority order',
                'name' => 'Name',
                'created' => 'Created on',
                'status' => 'Status',
            ],
            'empty' => [
                'title' => 'Create your first post-purchase offer',
                'body' => 'Build a one-click upsell that charges the card your customer just used — no re-entry.',
            ],
        ],

        'flow_status' => [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'draft' => 'Draft',
        ],

        'perf' => [
            'revenue' => 'Total revenue',
            'impressions' => 'Impressions',
            'orders' => 'Total post-purchase orders',
            'conversion' => 'Conversion rate',
            'charge_success' => 'Charge-success rate',
            'aov' => 'Avg post-purchase value',
            'chart_title' => 'Total revenue over time',
            'chart_days' => '{1}:count day with revenue|[2,*]:count days with revenue',
            'chart_peak' => 'Peak day :amount',
            'empty' => [
                'title' => 'No offer activity in this range yet',
                'body' => 'Once customers see and accept your offers, the funnel and revenue chart fill in here.',
            ],
        ],

        'activity' => [
            'title' => 'Activity',
            'col' => [
                'time' => 'Time',
                'event' => 'Event',
                'flow' => 'Flow / Offer',
                'customer' => 'Customer',
                'amount' => 'Amount',
                'order' => 'Parent order',
            ],
            'empty' => [
                'title' => 'No offer events yet',
                'body' => 'Impressions, accepts, declines and charges will appear here as customers interact with your offers.',
            ],
        ],

        'settings' => [
            'saved' => 'Settings saved.',
            'save' => 'Save',
            'partial_paid' => [
                'title' => 'Partial-paid order handling',
                'intro' => 'Choose what happens to a post-purchase upsell when the parent order is not yet fully paid (for example, an installment order that is still collecting payments).',
                'do_nothing' => 'Do nothing',
                'do_nothing_hint' => 'Keep the upsell — its child order is independent and fully paid on the saved card.',
                'remove_item' => 'Remove the upsell item',
                'remove_item_hint' => 'Remove the upsell from the order if the parent is not fully paid within the window below.',
                'default' => 'Default',
                'window' => 'Removal window',
                'window_hours' => '{1}:count hour|[2,*]:count hours',
            ],
        ],

        // The "Configure cross-sell" slide-over drawer (opened from an Offer node).
        'configure' => [
            'open' => 'Configure ":offer"',
            'crosssell' => 'Cross-sell',
            'single_product' => 'A single product',
            'product' => 'Product',
            'variants_all' => 'Variants: All',
            'price' => 'Price: :price',
            'title' => 'Configure cross-sell',
            'close' => 'Close',
            'saved' => 'Cross-sell saved.',
            'subtitle' => 'Highlight product offerings on the post-purchase page to encourage customers to add more products to the order they just completed.',
            'what_product' => 'What product do you want to offer as a post-purchase cross-sell?',
            'smart_select' => 'Smart select',
            'smart_select_hint' => 'Automatically generate the offer from the contents of the cart so each customer sees a relevant product.',
            'specific_products' => 'Specific products',
            'product_id' => 'Product ID: :id',
            'how_variants' => 'How are variants selected?',
            'variant_customer' => 'Customer selects a variant',
            'variant_merchant' => 'Merchant selects a variant for the customer',
            'variant_count' => '{1}This product only has :count variant|[2,*]This product only has :count variants',
            'purchase_options' => 'Purchase options',
            'purchase_one_time' => 'One-time',
            'purchase_subscription' => 'Subscription',
            'purchase_subscription_only' => 'Only sell as a subscription',
            'subscription_warning' => 'Because of Shopify limitations, only one subscription product can be included per order. If both the Post-Purchase Cross-Sell and the triggering item are subscriptions, the cross-sell will be skipped.',
            'discount_label' => 'Enter post-purchase discount amount',
            'discount_on_top' => 'Apply discount on top of the Subscribe & Save discount',
            'discount_on_top_hint' => 'When enabled, this discount stacks with any active Subscribe & Save discount instead of replacing it.',
            'shipping_label' => 'Post-purchase shipping fee',
            'shipping_free' => 'Free shipping',
            'shipping_charge' => 'Charge for shipping',
            'shipping_charge_hint' => 'Apply your store\'s standard shipping rate to the post-purchase item.',
            'display_options' => 'Display options',
            'show_timer' => 'Show timer',
            'show_timer_hint' => 'This is a countdown to create urgency to purchase.',
            'partial_paid_info' => 'Partially paid orders are automatically removed. You can change this from the',
            'partial_paid_link' => 'store\'s settings',
            'view_post_purchase' => 'View post-purchase',
        ],

        // The "Configure trigger" slide-over drawer (opened from the green Trigger node).
        'trigger_config' => [
            'open' => 'Configure trigger',
            'title' => 'Configure trigger',
            'subtitle' => 'Choose when this post-purchase offer is shown.',
            'close' => 'Close',
            'cancel' => 'Cancel',
            'save' => 'Save',
            'saved' => 'Trigger saved.',
            'event_label' => 'Trigger',
            'which_label' => 'Which purchases qualify?',
            'any_product' => 'Any product purchased',
            'any_product_hint' => 'Show the offer after any completed checkout, whatever was bought.',
            'specific_product' => 'A specific product',
            'specific_product_hint' => 'Only show the offer when this product was purchased.',
            'product_gid_label' => 'Shopify product ID',
            'collection' => 'A specific collection',
            'collection_hint' => 'Only show the offer when a product from this collection was purchased.',
            'collection_gid_label' => 'Shopify collection ID',
            'tag' => 'Has a tag',
            'tag_hint' => 'Only show the offer when a purchased product carries this tag.',
            'tag_label' => 'Product tag',
            'min_order_value' => 'Order value over an amount',
            'min_order_value_hint' => 'Only show the offer when the order subtotal is over this amount.',
            'amount_label' => 'Minimum order value',
            'currency_symbol' => '₪',
        ],

        'builder' => [
            'title' => 'Flow Builder',
            'untitled' => 'Untitled flow',
            'missing' => 'That flow no longer exists.',
            'back' => 'Back to Post-Purchase Offers',
            'activate' => 'Activate',
            'pause' => 'Pause',
            'activate_blocked' => 'Fix the issues below before activating this flow.',
            'activated' => 'Flow activated — it will now show on the thank-you page.',
            'paused' => 'Flow paused — it no longer shows to customers.',
            'issues' => '{1}:count issue|[2,*]:count issues',
            'empty' => 'Add your first offer to start this flow.',
            'zoom_in' => 'Zoom in',
            'zoom_out' => 'Zoom out',
            'zoom_reset' => 'Reset view',
            'node' => [
                'trigger' => 'Trigger',
                'offer' => 'Offer',
                'end' => 'End flow',
                'next_offer' => 'Next offer',
            ],
            'trigger' => [
                'headline' => 'After the customer completes a checkout',
                'any_product' => 'Any product purchased',
                'specific_product' => 'A specific product purchased',
                'collection' => 'A product from a collection',
                'tag' => 'Tagged ":tag"',
                'min_order' => 'Order value over :amount',
            ],
            'branch' => [
                'accept' => 'Accept',
                'decline' => 'Decline',
            ],
            'error' => [
                'no_trigger' => 'Add a trigger so this flow knows when to show.',
                'no_offer' => 'Add at least one offer.',
                'missing_copy' => '":offer" needs a headline and a button label.',
                'dangling_branch' => 'This branch does not lead anywhere.',
            ],
        ],
    ],
];
