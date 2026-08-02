<?php

// Payments → Shopify Subscriptions (the Shopify-Payments pilot rail). Statuses
// mirror SHOPIFY'S contract vocabulary — the two rails never share an enum.
// Mirror every key in lang/he/shopify_subscriptions.php.
return [

    'empty' => 'No Shopify subscriptions yet. They appear here when a shopper subscribes at checkout.',
    'empty_needs_scopes' => 'If a shopper has already subscribed, the subscription exists at Shopify but this app cannot read it yet: Shopify gates subscription contracts behind an approved API access request (read_own_subscription_contracts, write_own_subscription_contracts, read_customer_payment_methods). Request it in the Partner Dashboard under API access; subscriptions appear here — and start billing — once it is granted.',

    'status' => [
        'ACTIVE' => 'Active',
        'PAUSED' => 'Paused',
        'CANCELLED' => 'Cancelled',
        'EXPIRED' => 'Expired',
        'FAILED' => 'Payment issue',
    ],

    'col' => [
        'attempts' => 'Billing attempts',
        'synced' => 'Synced',
        'stale' => 'Needs sync',
    ],

    'action' => [
        'menu' => 'Actions',
        'charge_now' => 'Charge next payment now',
        'charge_now_body' => 'Shopify is asked to bill the next payment immediately, on the saved card. The result (paid / failed) arrives from Shopify within a few minutes and appears under Billing attempts.',
        'charge_now_requested' => 'Charge requested — Shopify is processing it. The outcome appears under Billing attempts shortly.',
        'pause' => 'Pause',
        'resume' => 'Resume',
        'cancel' => 'Cancel',
        'cancel_body' => 'The subscription is cancelled at Shopify and the shopper is not billed again. This cannot be undone from here — a new subscription requires a new checkout.',
        'skip' => 'Skip next charge',
        'skip_body' => 'The next charge moves one full cycle forward. The subscription stays active and nothing is billed in between.',
        'reschedule' => 'Change next charge date',
        'reschedule_date' => 'Next charge date',
        'sync' => 'Sync from Shopify',
        'synced' => 'Re-read from Shopify.',
        'done' => 'Done — Shopify applied the change.',
        'failed' => 'Shopify did not apply the change',
    ],

    'detail' => [
        'title' => 'Subscription',
        'untitled' => 'Subscription',
        'customer_ref' => 'Customer #:id',
        'customer_pending_approval' => 'The shopper’s name and email are protected customer data — Shopify shows them to the app only after approving Protected Customer Data access (Partner Dashboard → API access). The link opens the customer in the Shopify admin meanwhile.',
        'subheading' => ':amount :cadence',
        'shopify_owns' => 'Shopify owns this subscription; this page mirrors it. Every change here is sent to Shopify and recorded only once Shopify accepts it.',
        'cadence' => 'Billing cycle',
        'items' => 'Items',
        'items_empty' => 'No items recorded yet. Sync from Shopify to fetch them.',
        'item' => 'Item',
        'qty' => 'Qty',
        'attempts' => 'Billing attempts',
        'attempts_empty' => 'No billing attempt yet. The first runs on the next charge date.',
        'cycle' => 'Cycle',
        'requested' => 'Requested',
        'outcome' => 'Outcome',
        'overview' => 'Overview',
        'customer' => 'Customer details',
        'created_on' => 'Created on',
        'paid_cycles' => 'Completed orders',
        'per_cycle_total' => 'Total per cycle',
        'tab_schedule' => 'Order schedule',
        'tab_history' => 'Order history',
        'scheduled' => 'Scheduled',
        'charge_now_row' => 'Charge now',
        'schedule_empty' => 'No upcoming charge — the subscription has no next billing date.',
        'schedule_projection_note' => 'Dates are projected from the billing cycle; Shopify owns the schedule. Only the next charge can be moved or billed early.',
        'activity' => 'Activity',
    ],

    // Product-line edits (draft → commit at Shopify).
    'lines' => [
        'add' => 'Add product',
        'edit' => 'Edit product',
        'remove' => 'Remove product',
        'remove_body' => 'The product is removed from this subscription at Shopify. Future charges no longer include it.',
        'product' => 'Product',
        'unit_price' => 'Unit price',
    ],

    'payment' => [
        'title' => 'Payment details',
        'expires' => 'Expires',
        'none' => 'No payment method on record.',
        'card_pending_approval' => 'The card is vaulted at Shopify; its brand and last digits become readable after the Protected Customer Data approval.',
        'send_update_email' => 'Send card-update email',
        'send_update_email_body' => 'Shopify emails the shopper a secure page to update their card. The card itself never passes through this app.',
        'update_email_sent' => 'Shopify is emailing the shopper the card-update page.',
    ],

    'attempt' => [
        'requested' => 'Requested',
        'succeeded' => 'Paid',
        'failed' => 'Failed',
        'challenged' => 'Needs shopper action',
        'pending' => 'Waiting for Shopify',
    ],

    // "every month" / "every 2 weeks" — the cadence in the merchant's words.
    'cadence' => [
        'every' => 'every :unit',
        'every_n' => 'every :count :unit',
    ],

    'interval' => [
        'DAY' => ['one' => 'day', 'many' => 'days'],
        'WEEK' => ['one' => 'week', 'many' => 'weeks'],
        'MONTH' => ['one' => 'month', 'many' => 'months'],
        'YEAR' => ['one' => 'year', 'many' => 'years'],
    ],

    'reason' => [
        'shopify_rejected' => 'Shopify rejected the request. The contract may have changed — refresh and try again.',
        'transport' => 'Could not reach Shopify. Try again in a moment.',
        'not_found' => 'Shopify no longer recognises this contract.',
        'bad_date' => 'Pick a future date.',
        'not_billable' => 'Only an active subscription can be charged.',
        'already_requested' => 'This cycle already has a billing attempt — check Billing attempts below.',
    ],
];
