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

        // Subscription summary + the per-plan billing rail.
        'subs_heading' => 'Subscriptions',
        'subs_engine' => 'Billing engine',
        'subs_plans' => 'Subscription plans',
        'subs_plans_count' => ':active active of :total',
        'subs_published' => 'Live at Shopify',
        'subs_published_count' => ':published of :total published',
        'subs_publish_hint' => 'A plan must be published before shoppers can subscribe at checkout.',
        'live_on_shopify' => 'Live at Shopify',
        'not_on_shopify' => 'Not published',
        'rail' => [
            'payplus' => 'PayPlus',
            'shopify_payments' => 'Shopify Payments',
        ],
        'publish' => [
            'cta' => 'Publish to Shopify',
            'done' => 'Published — shoppers can now subscribe to this product at checkout.',
            'failed' => 'Shopify did not accept the selling plan',
            'not_shopify_rail' => 'This plan bills through PayPlus, so it needs no Shopify selling plan.',
            'no_connection' => 'Connect the store to Shopify first.',
            'remove_cta' => 'Remove from Shopify',
            'remove_confirm' => 'Stop offering this subscription at checkout? Existing subscriptions keep billing.',
            'removed' => 'Removed — the product no longer offers this subscription at checkout.',
        ],
        'all_variants' => 'All variants',
        'variant' => 'Variant',
        'add_subscription_plan' => 'Add subscription plan',
        'add_one_time_plan' => 'Add one-time plan',
        'no_plans' => 'No plans on this variant yet.',
        'one_time_label' => 'One-time',
        'subscription_label' => 'Subscription',
        'edit_plan' => 'Edit plan',
        'activate_plan' => 'Activate',
        'set_draft_plan' => 'Set to draft',
        'unpublish_confirm' => 'Hide this plan from customers? It goes back to draft until you activate it again.',
        'status_activated' => 'Plan activated — customers can subscribe to it now.',
        'status_unpublished' => 'Plan moved to draft — it’s hidden from customers.',
        'move_up' => 'Move up',
        'move_down' => 'Move down',
        'plan_created' => 'Draft plan added — configure it now.',

        // Plan-row meta
        'ship_every' => 'Ship every :count :unit',
        'discount_pct' => ':value% off',
        'discount_fixed' => ':value off',
        'intro_pricing' => ':value% off first :count charges',
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

        'rail_label' => 'Billed by',
        'rail_hint' => 'Which engine charges this subscription. Leave on the store setting unless this product must differ.',
        'rail' => [
            'inherit' => 'Store setting (:rail)',
        ],
        'ship_label' => 'Ship this product every',
        'frequency_unit' => 'Frequency',

        'pricing_mode_label' => 'Recurring price',
        'pricing_mode_plan_price' => 'Plan price (catalog price with the plan discount)',
        'pricing_mode_keep_first' => 'Keep the first payment’s amount',
        'pricing_mode_keep_first_hint' => 'If the product is bought at a discounted price, every cycle keeps that price.',
        'pricing_mode_fixed' => 'Fixed amount per cycle',
        'fixed_amount_label' => 'Amount per cycle',
        'keep_first_unavailable_shopify_rail' => 'Not available when Shopify Payments bills this plan.',

        'offer_discount' => 'Offer a discount on this plan',
        'discount_label' => 'Discount',
        'intro_limit_toggle' => 'Limit the discount to the first charges',
        'intro_limit_hint' => 'The first payment at checkout counts as charge #1; after the last discounted charge the price returns to the regular price.',
        'intro_limit_count' => 'Number of discounted charges',

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
        'commitment_label' => 'Minimum term before the customer can leave',
        'commitment_help' => 'Until these charges are paid, the customer cannot pause or cancel from their own account. You still can, from here. Changing it later applies to new subscriptions only.',
        'commitment_count_label' => 'Number of charges',

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
        'activate' => 'Activate plan',
        'set_draft' => 'Set to draft',
        'status_hint' => 'Only active plans are offered to customers.',
    ],
];
