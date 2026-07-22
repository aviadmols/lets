<?php

// Invoicing — the text that lands on CUSTOMER-FACING accounting documents.
// Kept separate from settings.php because these strings are printed on real tax
// paperwork: they are read by an accountant, not by the merchant in an admin UI.
// Mirror every key in lang/he/invoicing.php.
return [

    // Document line descriptions, used only when the plan carries no item title.
    'line' => [
        'deposit' => 'Deposit — plan :reference',
        'installment' => 'Installment payment — plan :reference',
        'final_installment' => 'Final payment — plan :reference',
        'recurring' => 'Subscription payment — plan :reference',
        'upsell' => 'Additional purchase — plan :reference',
        'refund' => 'Refund — plan :reference',
        'cancellation' => 'Cancellation — plan :reference',
        'platform_order' => 'Order :reference',
        // Balances an item breakdown to the order total (shipping, fees, rounding).
        'adjustment' => 'Shipping, fees and adjustments',
    ],

    // Free-text remarks printed on the document, so it reconciles back to LETS.
    'remarks' => [
        'plan' => 'Plan :reference',
        'order' => 'Order :reference',
    ],
];
