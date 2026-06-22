<?php

/**
 * Storefront-facing strings for the deposit + installments entry (W9 Part C):
 * the product-page button, the deposit-calculator modal, and its states. These
 * render on the merchant's storefront (NOT the admin panel), so they are kept
 * plain + short. lang/he/storefront.php mirrors every key (RTL-aware).
 */
return [
    'installments' => [
        // Product-page button.
        'button_label' => 'Pay a deposit & reserve it',
        'button_sublabel' => 'Split the rest into installments',

        // Modal chrome.
        'modal_title' => 'Pay a deposit, get it reserved',
        'modal_intro' => 'Pay part now; we reserve your item and bill the rest in installments. It ships once it is fully paid.',
        'item' => 'Item',
        'total' => 'Total',
        'default_item' => 'Item',

        // Controls.
        'down_payment' => 'Down payment',
        'installments_count' => 'Number of installments',
        'frequency' => 'Billing frequency',
        'payment_day' => 'Charge day of month',
        'frequency_weekly' => 'Weekly',
        'frequency_biweekly' => 'Every 2 weeks',
        'frequency_monthly' => 'Monthly',

        // Schedule preview.
        'deposit_now' => 'Pay now (deposit)',
        'then' => 'Then',
        'per_installment' => ':amount × :count',
        'schedule_title' => 'Payment schedule',
        'installment_n' => 'Installment :n',
        'due_on' => 'Due :date',

        // Submit.
        'submit' => 'Continue to pay the deposit',
        'submitting' => 'Setting things up…',

        // States / errors.
        'unavailable_title' => 'Installments are not available for this item',
        'unavailable_body' => 'This product cannot be reserved with a deposit right now. Please continue with the standard checkout.',
        'error_generic' => 'Something went wrong. Please try again.',
        'error_price' => 'We could not price this item for installments.',
        'close' => 'Close',

        // The note written onto the deposit draft/order (links it to the plan).
        'deposit_note' => 'LETS installments deposit — plan :plan',
    ],
];
