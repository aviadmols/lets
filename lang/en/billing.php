<?php

// Billing / subscription domain strings. Mirror every key in lang/he/billing.php.
return [
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
    'charge_context' => [
        'deposit' => 'Deposit',
        'installment' => 'Installment',
        'recurring' => 'Recurring charge',
        'upsell' => 'Post-purchase upsell',
        'retry' => 'Retry',
        'manual' => 'Manual',
    ],

    // common labels
    'next_charge' => 'Next charge',
    'remaining_balance' => 'Remaining balance',
    'total_amount' => 'Total amount',
    'paid_amount' => 'Paid amount',

    // Refund (a row action on a succeeded ledger entry)
    'refund' => [
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

        'frequency' => [
            'weekly' => 'Weekly',
            'biweekly' => 'Every 2 weeks',
            'monthly' => 'Monthly',
        ],
    ],
];
