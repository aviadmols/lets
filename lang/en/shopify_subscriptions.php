<?php

// Payments → Shopify Subscriptions (the Shopify-Payments pilot rail). Statuses
// mirror SHOPIFY'S contract vocabulary — the two rails never share an enum.
// Mirror every key in lang/he/shopify_subscriptions.php.
return [

    'empty' => 'No Shopify subscriptions yet. They appear here when a shopper subscribes at checkout.',

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
        'pause' => 'Pause',
        'resume' => 'Resume',
        'cancel' => 'Cancel',
        'cancel_body' => 'The subscription is cancelled at Shopify and the shopper is not billed again. This cannot be undone from here — a new subscription requires a new checkout.',
        'done' => 'Done — Shopify applied the change.',
        'failed' => 'Shopify did not apply the change',
    ],

    'reason' => [
        'shopify_rejected' => 'Shopify rejected the request. The contract may have changed — refresh and try again.',
        'transport' => 'Could not reach Shopify. Try again in a moment.',
        'not_found' => 'Shopify no longer recognises this contract.',
        'bad_date' => 'Pick a future date.',
    ],
];
