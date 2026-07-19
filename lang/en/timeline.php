<?php

// Timeline event-kind labels (EventPresenter). Mirror in lang/he/timeline.php.
return [
    'kind' => [
        'plan_created' => 'Plan created',
        'charge_succeeded' => 'Charge succeeded',
        'charge_failed' => 'Charge failed',
        'retry_scheduled' => 'Retry scheduled',
        'refund_succeeded' => 'Refund issued',
        'state_changed' => 'Status changed',
        'plan_edited' => 'Subscription edited',
        'plan_completed' => 'Plan completed',
        'plan_cancelled' => 'Plan cancelled',
        'plan_paused' => 'Plan paused',
        'fulfillment_released' => 'Order released for fulfillment',
        'email_sent' => 'Email sent',
        'webhook_received' => 'Webhook received',
        'generic' => 'Activity',
    ],

    // Field labels for a "Subscription edited" summary (old → new).
    'field' => [
        'next_charge_at' => 'Next charge',
        'amount' => 'Amount',
        'items' => 'Products',
    ],
];
