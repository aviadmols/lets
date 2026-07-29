<?php

// Customers → Gift orders. A loyalty campaign: subscribers past a cycle threshold
// receive a free product, shipped to the address their store holds TODAY.
// Mirror every key in lang/he/gifts.php.
return [

    'title' => 'Gift orders',

    'rule_heading' => 'Who gets a gift',
    'rule_intro' => 'Choose how many paid cycles earn a gift, and what to send. Nothing is created until you preview and confirm.',

    'field' => [
        'title' => 'Campaign name',
        'title_placeholder' => 'e.g. Thank-you gift, March',
        'min_cycles' => 'Minimum paid cycles',
        'min_cycles_hint' => 'Counts charges that actually succeeded — a failed attempt is not a cycle the customer paid for.',
        'product' => 'The gift',
        'product_placeholder' => 'Search a product by name or SKU…',
        'change_product' => 'Change',
        'gift_value' => 'Gift value: :value — charged at 0, so your reports still show what you gave.',
        'shipping_label' => 'Shipping method name',
        'shipping_label_hint' => 'Printed on the order. Shipping is free on a gift.',
    ],

    'preview_heading' => 'Who qualifies',
    'preview_empty' => 'Nobody has reached that many paid cycles yet.',
    'preview_summary' => ':qualify qualify · :ready will receive a gift now',
    'preview_more' => 'Showing the first :shown of :total. The counts above cover everyone.',
    'attention_more' => 'Showing :shown of :total that need attention.',
    'already_gifted' => 'already gifted',

    'col' => [
        'cycles' => 'Paid cycles',
        'rail' => 'Billing',
    ],

    'rail' => [
        'payplus' => 'PayPlus',
        'shopify' => 'Shopify',
    ],

    'action' => [
        'save' => 'Save campaign',
        'new' => 'New campaign',
        'edit' => 'Edit',
        'preview' => 'Preview recipients',
        'generate' => 'Send :count gift orders',
        'send' => 'Send now',
        'generate_confirm' => ':count free orders will be created in your store, shipped to each customer’s current address. Continue?',
        'retry' => 'Retry',
        'export' => 'Export list with addresses (CSV)',
    ],

    // The spreadsheet. Addresses are read from the store as the file is built, so
    // it says where each gift would go today.
    'export' => [
        'col' => [
            'customer' => 'Customer',
            'email' => 'Email',
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'address1' => 'Street address',
            'address2' => 'Address line 2',
            'city' => 'City',
            'zip' => 'Postal code',
            'country' => 'Country',
            'phone' => 'Phone',
            'company' => 'Company',
            'address_source' => 'Address taken from',
            'note' => 'Note',
        ],
        'source' => [
            'customer_profile' => 'Customer profile',
            'origin_order' => 'The order they subscribed with',
        ],
        'truncated' => ':count more recipients were left out of this file.',
    ],

    'editing' => 'Editing the saved campaign “:title”. Saving updates it; sending creates the orders.',
    'saved' => 'Campaign saved. Nothing has been created yet — send it when you are ready.',
    'draft_empty' => 'Saved, not sent yet.',
    'generated' => 'Creating :count gift orders. They appear below as each one lands.',
    'retry_queued' => 'Queued again.',
    'needs_check' => 'Check in your store',

    'past_heading' => 'Past campaigns',
    'campaign_summary' => ':product · at least :cycles paid cycles · :status',

    'status' => [
        'draft' => 'Draft',
        'generating' => 'Creating orders…',
        'completed' => 'Completed',
        'completed_with_errors' => 'Completed, some skipped',
    ],

    'recipient_status' => [
        'pending' => 'Waiting',
        'creating' => 'In progress',
        'created' => 'Sent',
        'skipped' => 'Skipped',
        'failed' => 'Failed',
        'unresolved' => 'Needs checking',
    ],

    'reason' => [
        'no_address' => 'No shipping address on file — a gift needs somewhere to go.',
        'address_access_pending' => 'Shopify has not granted this app access to customer addresses. Select the Address field in the Partner Dashboard under Protected customer data, then retry.',
        'no_price' => 'The gift has no price, so its value could not be shown on the order.',
        'api_error' => 'The store refused the order. Retry once the cause is fixed.',
        'unknown_outcome' => 'Your store broke while creating this order, so we could not tell whether it was created. We looked and did not find it — check your orders before doing anything, because retrying could send a second gift.',
    ],

    'error' => [
        'no_title' => 'Give the campaign a name.',
        'no_product' => 'Choose the gift product.',
        'no_price' => 'This product has no price in the catalog — refresh products, or pick another gift.',
    ],

    'default_shipping_label' => 'Gift shipping — free',
    'default_line_name' => 'Gift',
    'order_note' => 'LETS gift order — campaign ":campaign". Value :value, discounted 100%.',
];
