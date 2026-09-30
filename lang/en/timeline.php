<?php

// Timeline event-kind labels (EventPresenter). Mirror in lang/he/timeline.php.
return [
    'kind' => [
        'plan_created' => 'Plan created',
        'plan_created_manually' => 'Added by hand — no payment attached',
        'charge_refused_no_charge_plan' => 'Not charged — this subscription is free',
        'charge_refused_zero_amount' => 'Not charged — this cycle is worth nothing',
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
        'document_restamped' => 'Invoice re-sent to the store order by the merchant',
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
        'store_order_skipped' => 'Charged — no store order, as this store is set up',
        'shopify_subscription_resumed' => 'Subscription resumed',
        'shopify_subscription_rescheduled' => 'Next charge date changed',
        'shopify_subscription_bill_now' => 'Immediate charge requested from Shopify',
        'shopify_subscription_cycle_advanced' => 'Cycle paid — next charge date moved on',
        'shopify_subscription_products_edited' => 'Subscription products changed',
        'shopify_subscription_card_update_email' => 'Card-update email sent to the shopper',
        'card_updated' => 'The customer updated their card',
        'subscription_awaiting_activation' => 'Paid — waiting for the customer to activate it',
        'subscription_activated' => 'Subscription activated',
        'activation_link_sent' => 'Activation link emailed',
        'activation_link_revoked' => 'Activation links revoked',
        'payment_method_token_recovered' => 'Saved card found at PayPlus and attached',
        'payment_method_token_needs_confirmation' => 'Saved card found at PayPlus — not attached until you confirm it is the customer’s',
        'card_update_started' => 'The customer opened the card-update page',
        'card_update_link_sent' => 'A card-update link was created',
        'charge_repeat_blocked' => 'Charge stopped — this subscription was already charged in the last 24 hours',
        'invoicing_report_refused' => 'A store order was not invoiced — the report did not match the connected store or the money LETS recorded',
        'charge_above_consent' => 'Charge stopped — above the amount or frequency the customer agreed to',
        'consent_override_approved' => 'Charge above the customer’s consent approved by the merchant',
        'charge_repeat_approved' => 'A second charge within 24 hours — explicitly approved by an admin',
        'cycles_forgiven' => 'Missed cycles were not collected — per the store\'s setting; the next charge stays on the usual day',
        'card_update_failed' => 'The customer tried to update their card — it was declined',
        'card_update_not_saved' => 'The customer completed the PayPlus page, but the card was not saved to the subscription',
        'account_action' => 'The customer used a self-service action',
        'customer_address_updated' => 'The customer updated their address in the store',
        'campaign_email_sent' => 'Campaign email sent',
        'campaign_login_used' => 'Signed in from a campaign email link',
        'campaign_unsubscribed' => 'Unsubscribed from campaign emails',
        // The charge pipeline's own steps.
        'charge_attempt_started' => 'Charge attempt started',
        'charge_retry_scheduled' => 'Charge failed — another attempt is scheduled',
        'charge_in_flight' => 'Not started — another attempt for this payment is already in progress',
        'charge_needs_reconcile' => 'Charge outcome unknown — check PayPlus before charging again',
        'charge_reconciled' => 'Unknown charge resolved after checking PayPlus',
        'charging_paused' => 'Not charged — live charging is switched off for this store',
        'charging_resumed_rolled_forward' => 'Live charging switched back on — the missed charge date moved forward',
        'consent_missing' => 'Not charged — no stored customer consent for future charges',
        'manual_payment_pending' => 'Not charged — the previous payment request is still unpaid',
        'payment_status_changed' => 'Payment status changed',
        // Refunds and cancellations.
        'refund_requested' => 'Refund requested',
        'store_refund_synced' => 'The store order was updated with the refund',
        'store_refund_sync_failed' => 'Refunded, but updating the store order failed',
        'restocked' => 'Stock returned to the store',
        'order_cancelled_by_merchant' => 'Order cancelled by the merchant',
        // How a plan came to exist.
        'deposit_plan_created' => 'Deposit and installments plan created',
        'deposit_paid_plan_activated' => 'Deposit paid — plan activated',
        'recurring_plan_created' => 'Subscription created',
        'subscription_imported' => 'Imported from a file',
        'subscription_import_updated' => 'Updated from an import file',
        'subscription_import_released' => 'Imported subscription released for charging',
        // Post-purchase upsells.
        'upsell_charge_succeeded' => 'Post-purchase offer charged',
        'upsell_charge_failed' => 'Post-purchase offer — the card was declined',
        'upsell_child_order_failed' => 'Post-purchase offer charged, but creating its store order failed',
        'upsell_no_payment_method' => 'Post-purchase offer not charged — no saved card',
        // Privacy requests.
        'customer_redacted' => 'Customer data erased (privacy request)',
        'shop_redacted' => 'Store data erased (privacy request)',
        'customer_data_exported' => 'Customer data exported (privacy request)',
        'generic' => 'Activity',
    ],

    // Field labels for a "Subscription edited" summary (old → new).
    'field' => [
        'next_charge_at' => 'Next charge',
        'amount' => 'Amount',
        'items' => 'Products',
        'billing_frequency' => 'Billing frequency',
    ],

    /*
    | This change was part of a bulk edit. Shown on the subscription's OWN feed,
    | because otherwise a merchant reads "Next charge: 3 Oct → 10 Oct, by Dana" on
    | four thousand subscriptions with no way to tell it was one click.
    */
    'bulk_edit' => 'as part of bulk edit #:id',

    // The verb a shopper clicked (account_action_failed summary).
    'action' => [
        'pause' => 'Pause subscription',
        'resume' => 'Resume subscription',
        'cancel' => 'Cancel subscription',
        'skip' => 'Skip next delivery',
        'reschedule' => 'Change next charge date',
        'items' => 'Edit next order',
        'accept_offer' => 'Accept an offer',
        'update_card' => 'Update card',
        // Why a status moved (status_changed context).
        'first_payment_succeeded' => 'the first payment went through',
        'charge_attempted' => 'a charge was attempted',
        'charge_failed' => 'a charge failed',
        'dunning_resumed' => 'retries resumed',
        'unpaid_cycle_held' => 'retries ran out — held until the cycle is paid',
        'held_for_unpaid_cycle' => 'retries ran out — the subscription is paused until this cycle is paid',
        'unpaid_cycle_settled' => 'the unpaid cycle was paid',
        'payment_recovered' => 'a payment came through',
        'activated' => 'the customer activated it',
        'paid_awaiting_activation' => 'paid — waiting for the customer to activate it',
        'card_replaced' => 'the card was replaced',
        'switch_scheduled' => 'a scheduled switch took effect',
        'paused' => 'paused',
        'resumed' => 'resumed',
        'cancelled' => 'cancelled',
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
    // Between the two sides of a change ("old → new").
    'arrow' => '→',

    // Which charge of a subscription.
    'charge_n' => 'Charge #:n',

    // A reminder email's timing.
    'offset_hours' => 'sent :n hours before the charge',

    // "Label: value" labels for the facts under a row's title (TimelineSummary).
    'label' => [
        'email' => 'Email',
        'offer' => 'Offer',
        'product' => 'Product',
        'status' => 'Status',
        'cycles_skipped' => 'Cycles not collected',
        'period' => 'Period',
        'consented_amount' => 'Consented amount',
        'next_charge' => 'Next charge',
        'first_charge' => 'First charge',
        'still_due' => 'Unpaid cycle due',
        'updated_fields' => 'Updated',
        'address' => 'Address',
        'charge_type' => 'Charge type',
        'added_lines' => 'Products added',
        'document_type' => 'Document type',
        'document_number' => 'Document number',
        'for' => 'For',
        'consent_for' => 'Consent needed for',
        'card' => 'Card',
        'approval_number' => 'Approval number',
        'attempt' => 'Attempt',
        'reason' => 'Reason',
        'next_retry' => 'Next attempt',
        'last_charge' => 'Last charged',
        'sent_to_gateway' => 'Sent to PayPlus',
        'resolved_as' => 'Resolved as',
        'reported_total' => 'Store reported',
        'recorded_total' => 'LETS recorded',
        'price' => 'Price',
        'coupon' => 'Coupon',
        'deposit' => 'Deposit',
        'total' => 'Total',
        'installments' => 'Installments',
        'frequency' => 'Frequency',
        'order' => 'Order',
        'refund_request' => 'Refund request',
        'store_reference' => 'Store reference',
        'paid_cycle' => 'Paid cycle',
        'sent_to' => 'Sent to',
        'channel' => 'Via',
        'plans_updated' => 'Subscriptions updated',
        'file' => 'File',
        'line' => 'Line',
        'membership_id' => 'Member ID',
        'switch' => 'Subscription',
        'records' => 'Records',
        'campaign' => 'Campaign',
    ],

    // Why the system acted (the cause said first on a row).
    'trigger' => [
        'merchant' => 'Charged from the admin',
        'retry' => 'Automatic retry',
        'auto_renewal' => 'Automatic renewal',
        'schedule' => 'Scheduled payment',
        'store_order_paid' => 'Per the paid store order',
        'payplus_callback' => 'Per PayPlus confirmation',
        'store_webhook' => 'Per the store\'s update',
        'store_report' => 'Per the store\'s report',
        'privacy_request' => 'Per a privacy request',
    ],

    // Where a plan or a refund came from.
    'source' => [
        'admin_manual' => 'Added by hand in the admin',
        'csv_import' => 'Imported from a CSV file',
        'import_release' => 'Released from an import',
        'wc_cart_gateway' => 'Paid at the store checkout',
        'account_offer' => 'From an offer in the customer area',
        'store' => 'Refunded in the store',
    ],

    // Which email was sent.
    'email_template' => [
        'first_payment_welcome' => 'Welcome after the first payment',
        'recurring_payment_reminder' => 'Reminder before a charge',
        'manual_recurring_payment' => 'Payment request',
        'charge_succeeded' => 'Payment confirmation',
        'charge_failed' => 'Payment failed',
        'plan_cancelled' => 'Cancellation confirmation',
    ],

    // Why a charge or a check refused, in plain words.
    'reason' => [
        'live_charging_resumed' => 'live charging was switched back on',
        'amount_above_consent' => 'the amount is above what the customer agreed to',
        'cadence_tighter_than_consent' => 'charged more often than the customer agreed to',
        'stolen_or_lost_decline' => 'the old card was declined as stolen or lost',
        'not_proven_same_card' => 'not proven to be the same card',
        'order_not_created' => 'the store order was not created',
        'no_token' => 'PayPlus returned no saved card',
        'link_revoked' => 'the link had been revoked',
        'site_required' => 'the report did not say which store sent it',
        'site_mismatch' => 'the report came from a different store',
        'total_exceeds_recorded' => 'the total is above what LETS recorded',
    ],

    // Post-purchase flow / product plan statuses.
    'flow_status' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],

    // What a charge or a document was for.
    'context' => [
        'deposit' => 'Deposit',
        'installment' => 'Installment',
        'final_installment' => 'Final installment',
        'recurring' => 'Subscription renewal',
        'recurring_cycle' => 'Subscription renewal',
        'upsell' => 'Post-purchase offer',
        'platform_order' => 'Store order',
        'refund' => 'Refund',
        'cancellation' => 'Cancellation',
        'retry' => 'Retry',
        'manual' => 'Manual payment',
        'gateway' => 'Store checkout (PayPlus)',
        'account_offer' => 'Customer-area offer',
    ],

    'consent_context' => [
        'installments' => 'installments',
        'recurring' => 'a recurring subscription',
        'upsell' => 'post-purchase offers',
    ],

    // Accounting document types (Green Invoice codes + policy names).
    'document_type' => [
        '300' => 'Transaction invoice',
        '305' => 'Tax invoice',
        '320' => 'Tax invoice / receipt',
        '330' => 'Credit note',
        '400' => 'Receipt',
        '405' => 'Donation receipt',
        'invoice_receipt' => 'Tax invoice / receipt',
        'tax_invoice_receipt' => 'Tax invoice / receipt',
        'tax_invoice' => 'Tax invoice',
        'receipt' => 'Receipt',
        'credit_invoice' => 'Credit note',
    ],

    'refund_mode' => [
        'cancel_order' => 'Cancel the whole order',
        'refund_full' => 'Full refund',
        'refund_partial' => 'Partial refund',
    ],

    'offer_mode' => [
        'add' => 'Added alongside the current subscription',
        'replace' => 'Replaces the current subscription',
    ],

    'address_type' => [
        'billing' => 'Billing',
        'shipping' => 'Shipping',
    ],

    'channel' => [
        'email' => 'Email',
        'sms' => 'SMS',
        'copy' => 'Copied link',
    ],

    'contact_field' => [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'national_id' => 'ID number',
        'address' => 'Address',
    ],

    'frequency' => [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'biweekly' => 'Every two weeks',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'yearly' => 'Yearly',
    ],

    'every' => [
        'daily' => 'Every :n days',
        'weekly' => 'Every :n weeks',
        'biweekly' => 'Every :n two-week periods',
        'monthly' => 'Every :n months',
        'quarterly' => 'Every :n quarters',
        'yearly' => 'Every :n years',
    ],

    // Yes/no facts, said only when true.
    'flag' => [
        'token_captured' => 'Card saved for future charges',
        'token_not_captured' => 'No card saved for future charges',
        'is_final' => 'Final payment',
        'restock' => 'Stock returned',
        'needs_reconcile' => 'Needs a manual check',
        'verified_absent_by_merchant' => 'The merchant confirmed no document existed',
        'reconciled_by_merchant' => 'Matched to an existing document by the merchant',
        'skipped_delivery' => 'Delivery skipped',
        'will_retry' => 'Another attempt will follow',
        'repaired' => 'Completed on a later pass',
    ],
];
