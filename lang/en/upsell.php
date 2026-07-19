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

    // Renderer state-machine labels (shared card).
    'adding' => 'Adding…',
    'error_generic' => 'We could not add that. Please try again.',

    // The Filament / storefront preview host.
    'preview' => [
        'title' => 'Post-purchase card preview',
        'sample_headline' => 'Complete the look',
        'sample_product' => 'Signature Add-On',
        'sample_subcopy' => 'A perfect companion to what you just bought — added straight to your order, charged to the card you already used.',
    ],

    // ===================================================================
    // Settings → Upsell card design (Phase 3) — the element/style builder.
    // ===================================================================
    'appearance' => [
        'title' => 'Upsell card design',
        'intro' => 'Design the one-click post-purchase card your customers see. Changes preview live on the right; the price, the add button and the charge disclosure are always shown and cannot be removed.',
        'save' => 'Save design',
        'saved' => 'Card design saved.',
        'preview_hint' => 'A live preview of your real thank-you card. It updates as you edit; click “Save design” to keep your changes.',

        'brand' => [
            'heading' => 'Brand',
            'intro' => 'Colours and shapes. The default is a clean monochrome card — the drama comes from the type.',
            'accent' => 'Accent colour',
            'accent_help' => 'Used for the add-to-order button and highlights.',
            'accent_text' => 'Accent text colour',
            'accent_text_help' => 'The text colour on a solid accent button.',
            'theme' => 'Theme',
            'button' => 'Button style',
            'corners' => 'Button corners',
            'shadow' => 'Card shadow',
            'font' => 'Font',
        ],

        'layout' => [
            'heading' => 'Layout',
            'intro' => 'How the card is arranged.',
            'arrangement' => 'Arrangement',
            'image_ratio' => 'Image shape',
            'decline' => 'Decline treatment',
            // Arrangement values.
            'stacked' => 'Stacked',
            'media_side' => 'Image beside',
        ],

        'elements' => [
            'heading' => 'Elements',
            'intro' => 'Turn parts of the card on or off, and drag to reorder them. Price, add button and charge disclosure are always on.',
            'show' => 'Show',
            'locked' => 'Always shown',
        ],

        'copy' => [
            'heading' => 'Copy',
            'intro' => 'Reusable text. Leave blank to use the built-in default.',
            'eyebrow' => 'Eyebrow label',
            'badge' => 'Badge',
            'badge_help' => 'A small pill (e.g. “Best seller”). Leave blank to hide it.',
            'trust' => 'Trust line',
        ],

        // Element labels (each row in the Elements list).
        'element' => [
            'eyebrow' => 'Eyebrow (small label)',
            'badge' => 'Badge',
            'timer' => 'Countdown timer',
            'image' => 'Product image',
            'headline' => 'Headline',
            'product_name' => 'Product name',
            'subcopy' => 'Description',
            'price' => 'Price',
            'save' => 'Savings',
            'trust' => 'Trust line',
            'cta' => 'Add-to-order button',
            'decline' => 'Decline link',
            'disclosure' => 'Charge disclosure',
        ],

        // Token option labels.
        'theme' => ['light' => 'Light', 'dark' => 'Dark'],
        'button' => ['solid' => 'Solid', 'outline' => 'Outline'],
        'radius' => ['sharp' => 'Sharp', 'soft' => 'Soft', 'pill' => 'Pill'],
        'shadow' => ['none' => 'None', 'soft' => 'Soft', 'elevated' => 'Elevated'],
        'font' => ['heebo' => 'Heebo', 'system' => 'System'],
        'ratio' => ['natural' => 'Natural', 'square' => 'Square'],
        'decline' => ['link' => 'Text link', 'button' => 'Button'],
    ],

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
            'save' => 'Save',
            'saved' => 'Cross-sell saved.',
            'subtitle' => 'Highlight product offerings on the post-purchase page to encourage customers to add more products to the order they just completed.',
            'what_product' => 'What product do you want to offer as a post-purchase cross-sell?',
            'smart_select' => 'Smart select',
            'smart_select_hint' => 'Automatically generate the offer from the contents of the cart so each customer sees a relevant product.',
            'specific_products' => 'Specific products',
            'product_id' => 'Product ID: :id',
            'offer_title_label' => 'Offer title',
            'headline_label' => 'Headline',
            'headline_placeholder' => 'e.g. Wait! Add this before you go',
            'accept_cta_label' => 'Button label',
            'base_price_label' => 'Base price',
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

        // The searchable product picker shared by the offer + trigger drawers.
        'picker' => [
            'search_label' => 'Search products',
            'search_placeholder' => 'Search by name or SKU',
            'min_chars' => 'Type at least 3 characters to search your products.',
            'refresh' => 'Refresh products',
            'refresh_queued' => 'Refreshing products from your store…',
            'refresh_needs_connection' => 'Connect the store first — there are no products to pull yet.',
            'selected' => 'Selected product',
            'change' => 'Change',
            'sku' => 'SKU: :sku',
            'default_variant' => 'Default',
            'empty_title' => 'No products found',
            'empty_hint' => 'Nothing matched. Try another term, or refresh to sync your catalog first.',
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
            'product_pick_label' => 'Which product?',
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
            'rename_label' => 'Flow name',
            'renamed' => 'Flow name updated.',
            'add_accept_offer' => 'Add the next offer on accept',
            'add_decline_offer' => 'Add the next offer on decline',
            'add_step' => 'Add step',
            'delete_offer' => 'Delete this offer',
            'delete_confirm' => 'Delete this offer? Any step that leads to it will end the flow instead.',
            'deleted' => 'Offer deleted.',
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
