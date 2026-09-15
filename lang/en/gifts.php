<?php

// Customers → Gift orders. A loyalty campaign: subscribers past a cycle threshold
// receive a free product, shipped to the address their store holds TODAY.
// Mirror every key in lang/he/gifts.php.
return [

    'title' => 'Gift orders',

    'group' => [
        'who' => 'The rule',
        'what' => 'The gift',
    ],
    'rule_heading' => 'Who gets a gift',
    'rule_intro' => 'Choose how many paid cycles earn a gift, and what to send. Nothing is created until you preview and confirm.',

    'field' => [
        'title' => 'Campaign name',
        'title_placeholder' => 'e.g. Thank-you gift, March',
        'min_cycles' => 'Minimum paid cycles',
        'min_cycles_hint' => 'Counts charges that actually succeeded — a failed attempt is not a cycle the customer paid for.',
        'source_products' => 'Subscribers of which products',
        'source_products_placeholder' => 'Search a product to narrow the campaign…',
        'source_products_all' => 'Left empty, every subscription counts. Add products to gift only the people who subscribe to them.',
        'source_products_hint' => 'Only subscribers of these products qualify. Someone who subscribes to any one of them is in.',
        'source_emails' => 'Specific customers by email',
        'source_emails_placeholder' => 'One email per line (commas work too)…',
        'source_emails_hint' => 'Left empty, everyone who meets the rule receives one. Add emails to send only to those people (the rule still applies to them).',
        'source_emails_count' => 'Narrowed to :count addresses. Someone who is not an active subscriber will not appear — a gift needs a shipping address.',
        'product' => 'The gift',
        'product_placeholder' => 'Search a product by name or SKU…',
        'add_product_placeholder' => 'Add another product to the gift…',
        'change_product' => 'Change',
        'gift_value' => 'Gift value: :value — charged at 0, so your reports still show what you gave.',
        'shipping_label' => 'Shipping method name',
        'shipping_label_hint' => 'Printed on the order. Shipping is free on a gift.',
    ],

    'preview_heading' => 'Who qualifies',
    'preview_empty' => 'Nobody has reached that many paid cycles yet.',
    'preview_summary' => 'will receive a gift now, out of :qualify who qualify',
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
        'remove_source' => 'Remove',
        'remove_item' => 'Remove',
        'preview' => 'Preview recipients',
        'generate' => 'Send :count gift orders',
        'send' => 'Send now',
        'generate_confirm' => ':count free orders will be created in your store, shipped to each customer’s current address. Continue?',
        'retry' => 'Retry',
        'export' => 'Export list with addresses (CSV)',
    ],

    // The spreadsheet, in a courier sheet's shape. Addresses are read from the
    // store as the file is built, so it says where each gift would go today.
    'export' => [
        'col' => [
            'name' => 'Name',
            'phone' => 'Phone',
            'city' => 'City',
            'street' => 'Street',
            'building' => 'House',
            'entrance' => 'Entrance',
            'apartment' => 'Apartment',
            'floor' => 'Floor',
            'note' => 'Notes to write on the delivery',
            // Labels of the address form on the customer screen.
            'address1' => 'Street address',
            'address2' => 'Address line 2',
            'zip' => 'Postal code',
            'country' => 'Country',
        ],
    ],

    // The export runs in the background: every line is a store read, and a real
    // list is minutes of work.
    'export_run' => [
        'title' => 'List export',
        'starting' => 'Starting…',
        'busy' => 'Export running…',
        'counting' => 'Gathering who qualifies…',
        'working' => 'Reading addresses from your store. Keep working — the file downloads by itself when it is ready.',
        'ready' => 'The file is ready: :count recipients (finished at :time).',
        'download' => 'Download the file',
        'preparing' => 'Preparing the file…',
        'failed' => 'The export stopped before it finished. Please export again.',
        'already_running' => 'An export is already running. The file downloads when it finishes.',
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
        'no_address' => 'No shipping address anywhere — not on the store profile, not on the origin order, not on the subscription in LETS. Add one on the subscription screen and retry.',
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
