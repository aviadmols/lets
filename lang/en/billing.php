<?php

// Billing / subscription domain strings. Mirror every key in lang/he/billing.php.
return [
    // SaaS tier display names (App\Domain\Billing\BillingPlan). Today only "free".
    // Paid tiers add a key here matching the new enum case value.
    'plan_tier' => [
        'free' => 'Free',
        // 'starter' => 'Starter', 'growth' => 'Growth', 'pro' => 'Pro',
    ],

    // Plan-gate prompts: shown (as an upgrade nudge) when a tier limit is hit.
    // Never reached on FREE (everything is unlimited); wired for paid tiers.
    'gate' => [
        'upsell_flows' => [
            'title' => 'Upsell flow limit reached',
            'body' => 'Your current plan does not allow another upsell flow. Upgrade to add more.',
        ],
    ],

    // plan kinds
    'plan_kind' => [
        'installments' => 'Installments',
        'recurring' => 'Recurring subscription',
    ],

    // plan statuses
    'status' => [
        'draft' => 'Draft',
        'awaiting_first_payment' => 'Awaiting first payment',
        'active' => 'Active',
        'paused' => 'Paused',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],

    // payment-ledger statuses (ARCHITECTURE.md §3.3 PaymentLedgerStatus)
    'ledger_status' => [
        'pending' => 'Pending',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'refunded' => 'Refunded',
        'retry_scheduled' => 'Retry scheduled',
        'cancelled' => 'Cancelled',
    ],

    // charge contexts
    'col' => [
        'order' => 'Order',
    ],

    'detail' => [
        'title' => 'Payment',
        'email' => 'Email',
        'gateway' => 'Gateway response',
        'no_document' => 'No accounting document for this payment.',
        'fact' => [
            'approval' => 'Approval number',
            'card' => 'Card',
            'brand' => 'Card brand',
            'method' => 'Method',
            'payments' => 'Instalments',
            'status_code' => 'Status code',
            'status_description' => 'Status',
        ],
    ],
    'charge_context' => [
        'deposit' => 'Deposit',
        'installment' => 'Installment',
        'recurring' => 'Recurring charge',
        'upsell' => 'Post-purchase upsell',
        'retry' => 'Retry',
        'manual' => 'Manual',
        'gateway' => 'Store checkout (PayPlus)',
        'account_offer' => 'Account add-on',
    ],

    // common labels
    'next_charge' => 'Next charge',
    'remaining_balance' => 'Remaining balance',
    'total_amount' => 'Total amount',
    'paid_amount' => 'Paid amount',

    // Refund (a row action on a succeeded ledger entry)
    'refund' => [
        'order_label' => 'Refund whole order (:count charges)',
        'order_heading' => 'Refund every charge on this order?',
        'order_body' => 'All :count charges on this order — :amount — are refunded to the shopper, and each gets its own credit note. This cannot be undone.',
        'order_success' => 'Refunded :count charges. A credit note was issued for each.',
        'order_partial' => 'Refunded :refunded, :failed failed. The refunds that went through were kept.',
        'label' => 'Refund',
        'heading' => 'Refund this charge?',
        'body' => ':amount will be refunded to the customer via PayPlus. This cannot be undone.',
        'success' => 'Refund processed.',
        'failed' => 'Refund failed.',
    ],

    // Settings → Billing (per-shop billing policy, plan §4.7).
    'settings' => [
        'title' => 'Billing settings',
        'intro' => 'Control how charges retry, the installment bounds your storefront must stay within, what customers can do themselves, and the cancellation policy shown to them.',
        'save_cta' => 'Save billing settings',
        'saved' => 'Billing settings saved.',

        'rail' => [
            'heading' => 'Subscriptions engine',
            'intro' => 'Which system bills your recurring subscriptions. Installments and post-purchase upsells always charge through PayPlus.',
            'label' => 'Recurring subscriptions are billed by',
            'payplus' => 'PayPlus',
            'payplus_help' => 'The card is saved as a PayPlus token and this app charges each cycle. Works with any Shopify checkout setup.',
            'shopify_payments' => 'Shopify Payments',
            'shopify_payments_help' => 'Shoppers subscribe at the Shopify checkout; Shopify vaults the card and processes each cycle when this app requests it. Requires Shopify Payments and an install through the subscriptions (custom) app.',
            'switch_warning' => 'Switching does not move existing subscriptions between engines: active Shopify contracts stop billing if you leave the Shopify Payments engine, and PayPlus plans keep charging regardless of this choice.',
            'detected_shopify_payments' => 'This store sells through Shopify Payments, so it was set to bill subscriptions there and the PayPlus connection settings are hidden. Switch to PayPlus below to use a PayPlus terminal instead.',
        ],

        /*
         * The master tap on saved-token money. Deliberately its own section, and
         * deliberately worded so a merchant knows exactly what keeps working while
         * it is off: the store still sells, subscriptions still exist, dates still
         * show — only the automatic charging stops.
         */
        'charging' => [
            'heading' => 'Live charging',
            'intro' => 'Whether this store may charge saved cards automatically.',
            'live' => 'Charge subscriptions live',
            'live_help' => 'Turn this off while you check a migration. Subscriptions stay active and their charge dates stay visible, but no saved card is charged — not by the scheduler, not by a retry, not by "charge now". Your store keeps selling: a shopper paying at checkout is unaffected.',
            'overdue_heading' => 'Before you turn charging back on',
            'overdue_body' => 'Charge dates kept passing while charging was off, and :count subscriptions are now overdue. Turning it back on rolls each of them forward a whole cycle instead of billing them all at once — you will be told how many moved and how much is about to be charged.',
            'resumed_title' => 'Live charging is on',
            'resumed_body' => ':rolled overdue subscriptions were rolled forward a cycle. :due are now due within :days days, totalling :money.',
        ],

        'retries' => [
            'heading' => 'Payments & retries',
            'intro' => 'How a failed charge is retried before a plan is marked failed.',
            'backoff' => 'Retry backoff (hours)',
            'backoff_help' => 'Hours to wait before each retry, in order. Add a value per attempt (e.g. 4, 24, 72).',
            'max_attempts' => 'Maximum charge attempts',
            'max_attempts_help' => 'How many times a charge is attempted before it gives up.',
            'grace_days' => 'Failed-payment grace (days)',
            'grace_days_help' => 'How long a plan may stay in retry before it is failed.',
        ],

        'installments' => [
            'heading' => 'Installment rules',
            'intro' => 'The bounds every installment plan must respect. These are enforced on the server, so a storefront request can never go below your deposit floor or above your installment ceiling.',
            'min_deposit_percent' => 'Minimum deposit (%)',
            'min_deposit_percent_help' => 'The smallest up-front deposit, as a percentage of the order total.',
            'min_deposit_amount' => 'Minimum deposit amount',
            'min_deposit_amount_help' => 'Optional flat floor for the deposit, in the order currency. Leave blank for none.',
            'max_installments' => 'Maximum installments',
            'max_installments_help' => 'The most installments a customer may split the balance into.',
            'allowed_frequencies' => 'Allowed frequencies',
            'allowed_frequencies_help' => 'Which billing cadences customers may choose for installments.',
            'lock_fulfillment' => 'Lock fulfillment until fully paid',
            'lock_fulfillment_help' => 'Hold fulfillment until every installment is paid.',
        ],

        'self_service' => [
            'heading' => 'Customer self-service',
            'intro' => 'What customers can do from their portal magic link.',
            'allow_pause' => 'Allow customers to pause',
            'allow_pause_help' => 'Let customers pause (and resume) their own plans.',
            'allow_cancel' => 'Allow customers to cancel',
            'allow_cancel_help' => 'Let customers cancel their own plans.',
            'allow_skip' => 'Allow skipping the next delivery',
            'allow_skip_help' => 'Adds a "Skip next delivery" button. Turn it off if every cycle ships on a fixed date.',
            'allow_reschedule' => 'Allow changing the next charge date',
            'allow_reschedule_help' => 'Lets a customer move their next charge to a date they choose.',
            'allow_edit_items' => 'Allow editing the next order',
            'allow_edit_items_help' => 'Lets a customer add or change the products in their next order.',
            'single_subscription' => 'One subscription per customer',
            'single_subscription_help' => 'Refuses a second subscription at checkout when the customer already has a live one, and points them to their account area. It never touches subscriptions they already hold.',
        ],

        'policy' => [
            'heading' => 'Policy & terms',
            'intro' => 'Shown to customers and snapshotted into the consent record they accept.',
            'cancellation_text' => 'Cancellation policy',
            'cancellation_text_help' => 'Plain text describing how and when a customer may cancel.',
            'terms_version' => 'Terms version',
            'terms_version_help' => 'Bump this when your terms change so each consent records which version was accepted.',
            'support_email' => 'Support email',
            'support_email_help' => 'Where customers can reach you about their plan.',
        ],

        /* Every case of BillingFrequency — a filter offers them all, and a
           missing key renders as its own raw name in front of a merchant. */
        'frequency' => [
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'biweekly' => 'Every 2 weeks',
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'yearly' => 'Yearly',
        ],
    ],
];
