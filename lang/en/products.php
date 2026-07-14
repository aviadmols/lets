<?php

// Products screen (Work Package W1, Phase E — Recharge-style Products admin).
// LIST (ProductResource) + DETAIL (ProductDetail page) + the "Edit subscription
// plan" slide-over drawer. Mirror EVERY key 1:1 in lang/he/products.php — a
// missing he key is a release blocker. Currency via the ILS Money formatter.
return [

    // --- List header / banner ---
    'title' => 'Products',
    'plural' => 'Products',
    'singular' => 'Product',
    'markets_banner' => 'Plans and pricing here apply to your primary market. Per-market overrides are coming soon.',
    'refresh' => 'Refresh products',
    'refreshed' => 'Refreshing products from Shopify — this runs in the background.',
    'refresh_needs_connection' => 'Connect the store first — there are no credentials to pull products with yet.',
    'refreshed_one' => 'Product refreshed.',

    // --- List columns ---
    'col' => [
        'product' => 'Product',
        'shopify_status' => 'Shopify status',
        'online_store' => 'Online store',
        'purchase_types' => 'Purchase types',
        'plans' => '# of plans',
        'sku' => 'SKU',
        'updated' => 'Updated',
    ],
    'variants_count' => '{1} :count variant|[2,*] :count variants',
    'plans_count' => '{0} No plans|{1} :count plan|[2,*] :count plans',

    // --- Product status badges ---
    'status' => [
        'active' => 'Active',
        'draft' => 'Draft',
        'unlisted' => 'Unlisted',
    ],
    'online' => [
        'published' => 'Published',
        'unpublished' => 'Unpublished',
    ],

    // --- Purchase-type badges (derived from the plan templates) ---
    'purchase' => [
        'one_time' => 'One-time',
        'subscription' => 'Subscription',
        'none' => 'No purchase options',
    ],

    // --- Filters ---
    'filter' => [
        'product_status' => 'Product status',
        'online_status' => 'Online store status',
        'all' => 'All',
        'has_plans' => 'Has plans',
        'has_plans_yes' => 'Has plans',
        'has_plans_no' => 'No plans',
        'purchase_types' => 'Purchase types',
        'search_placeholder' => 'Search products…',
    ],

    // --- Empty states ---
    'empty' => [
        'first_run' => 'No products yet. Refresh from Shopify to import your catalog.',
        'no_results' => 'No products match your search or filters.',
    ],

    // --- Detail page ---
    'detail' => [
        'back' => 'Back to Products',
        'product_details' => 'Product details',
        'view_in_shopify' => 'View in Shopify',
        'price' => 'Price',
        'sku' => 'SKU',
        'no_sku' => 'No SKU',
        'variants_heading' => 'Variants & plans',
        'all_variants' => 'All variants',
        'variant' => 'Variant',
        'add_subscription_plan' => 'Add subscription plan',
        'add_one_time_plan' => 'Add one-time plan',
        'no_plans' => 'No plans on this variant yet.',
        'one_time_label' => 'One-time',
        'subscription_label' => 'Subscription',
        'edit_plan' => 'Edit plan',
        'move_up' => 'Move up',
        'move_down' => 'Move down',
        'plan_created' => 'Draft plan added — configure it now.',

        // Plan-row meta
        'ship_every' => 'Ship every :count :unit',
        'discount_pct' => ':value% off',
        'discount_fixed' => ':value off',
        'no_discount' => 'No discount',
        'channels_label' => 'Channels',

        // Side column
        'side' => [
            'title' => 'Details',
            'product_id' => 'Product ID',
            'variant_id' => 'Variant ID',
            'shopify_status' => 'Shopify status',
            'online_store' => 'Online store',
            'last_updated' => 'Last updated',
            'tags' => 'Tags',
            'no_tags' => 'No tags',
            'collection' => 'Collection',
            'collection_placeholder' => 'Not assigned',
        ],
    ],

    // --- Billing-frequency unit labels (singular; used where no count is shown) ---
    'unit' => [
        'daily' => 'day',
        'weekly' => 'week',
        'biweekly' => 'two weeks',
        'monthly' => 'month',
        'quarterly' => 'quarter',
        'yearly' => 'year',
    ],

    // --- The SAME units, pluralised for a count (trans_choice) ---
    // Used by ship_every / price_summary so "every 2 year" reads "every 2 years".
    // NOTE `biweekly` is already plural — a naive ":unit + s" would give "two weekss".
    'unit_choice' => [
        'daily' => 'day|days',
        'weekly' => 'week|weeks',
        'biweekly' => 'two weeks|two-week periods',
        'monthly' => 'month|months',
        'quarterly' => 'quarter|quarters',
        'yearly' => 'year|years',
    ],

    // --- "Edit subscription plan" slide-over drawer ---
    'plan_drawer' => [
        'title' => 'Edit subscription plan',
        'title_one_time' => 'Edit one-time plan',
        'subtitle' => 'Configure how customers subscribe to this product.',
        'close' => 'Close',
        'cancel' => 'Cancel',
        'save' => 'Save',
        'saved' => 'Plan saved.',

        'type_label' => 'Type',
        'type_subscription' => 'Subscription plan',
        'type_one_time' => 'One-time purchase',

        'ship_label' => 'Ship this product every',
        'frequency_unit' => 'Frequency',

        'offer_discount' => 'Offer a discount on this plan',
        'discount_label' => 'Discount',

        'plan_name_label' => 'Plan name (shown to customers)',
        'plan_name_placeholder' => 'e.g. Subscribe & save',

        'price_summary' => ':price every :count :unit',
        'price_summary_single' => ':price every :unit',

        'schedule_heading' => 'Charge and cut-off schedule',
        'charge_on_label' => 'Charge customers on',
        'charge_on_signup' => 'When customers sign up',
        'charge_on_day' => 'Day :day of the month',
        'expire_label' => 'Expire after a number of charges',
        'expire_count_label' => 'Number of charges',

        'channels_heading' => 'Channels',
        'channels_hint' => 'Where this plan can be offered.',
        'channel' => [
            'storefront_widget' => 'Storefront widget',
            'customer_portal' => 'Customer portal',
            'merchant_portal' => 'Merchant portal',
            'api' => 'API',
        ],

        'status_label' => 'Status',
        'status_active' => 'Active',
        'status_draft' => 'Draft',
    ],
];
