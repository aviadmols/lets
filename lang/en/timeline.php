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
        'customer_details_updated' => 'Contact details updated',
        'admin_note' => 'Note',
        'customer_impersonated' => 'Signed in to the store as this customer',
        'customer_viewed_as' => 'Viewed this customer\'s account area (read-only)',
        'plan_completed' => 'Plan completed',
        'plan_cancelled' => 'Plan cancelled',
        'plan_paused' => 'Plan paused',
        'fulfillment_released' => 'Order released for fulfillment',
        'email_sent' => 'Email sent',
        'webhook_received' => 'Webhook received',
        // Invoicing (Green Invoice). The label is all the Timeline shows — never the URL.
        'document_requested' => 'Invoice requested',
        'document_issued' => 'Invoice issued',
        'document_failed' => 'Invoice failed',
        'document_retried' => 'Invoice retried by the merchant',
        'document_force_issued' => 'Invoice issued after the merchant checked Green Invoice',
        'price_stepped_up' => 'Intro discount ended — price stepped up to the regular price',
        'checkout_discount_captured' => 'Coupon captured from the checkout order',
        // Account offers. A switch is NOT a cancellation — a churn report that
        // reads it as one is the reason these have kinds of their own.
        'account_offer_accepted' => 'Offer accepted in the customer area',
        'plan_switched' => 'Subscription switched',
        'account_offer_charge_failed' => 'Offer accepted, but the card was declined',
        'account_action_failed' => 'A customer-area action was refused',
        'store_order_failed' => 'Charged, but creating the store order failed',
        'shopify_subscription_resumed' => 'Subscription resumed',
        'shopify_subscription_rescheduled' => 'Next charge date changed',
        'shopify_subscription_bill_now' => 'Immediate charge requested from Shopify',
        'shopify_subscription_products_edited' => 'Subscription products changed',
        'shopify_subscription_card_update_email' => 'Card-update email sent to the shopper',
        'card_updated' => 'The customer updated their card',
        'campaign_email_sent' => 'Campaign email sent',
        'campaign_login_used' => 'Signed in from a campaign email link',
        'campaign_unsubscribed' => 'Unsubscribed from campaign emails',
        'generic' => 'Activity',
    ],

    // Field labels for a "Subscription edited" summary (old → new).
    'field' => [
        'next_charge_at' => 'Next charge',
        'amount' => 'Amount',
        'items' => 'Products',
    ],

    // The verb a shopper clicked (account_action_failed summary).
    'action' => [
        'pause' => 'Pause subscription',
        'resume' => 'Resume subscription',
        'cancel' => 'Cancel subscription',
        'skip' => 'Skip next delivery',
        'reschedule' => 'Change next charge date',
        'items' => 'Edit next order',
        'accept_offer' => 'Accept an offer',
    ],

    // Why the click was refused (account_action_failed summary).
    'result' => [
        'not_allowed' => 'not allowed for this subscription',
        'bad_state' => 'the subscription is not in a state that allows it',
        'invalid' => 'the subscription or offer was not found',
        'unavailable' => 'the offer is not available right now',
        'not_eligible' => 'the subscription is not eligible for the offer',
        'changed' => 'the offer changed while the page was open',
    ],
];
