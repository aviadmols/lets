<?php

// מנויים — רשימה ופירוט (docs/ux/30-subscriptions.md). שיקוף של lang/en/subscriptions.php.
return [
    'list' => [
        'title' => 'מנויים',
        'search_placeholder' => 'חיפוש לקוח',
        'col' => [
            'customer' => 'לקוח',
            'kind' => 'סוג',
            'status' => 'סטטוס',
            'next_charge' => 'חיוב הבא',
            'amount_balance' => 'סכום / יתרה',
        ],
        'empty' => [
            'first_run' => 'אין עדיין מנויים. צרו תוכנית על מוצר או קבלו מקדמה כדי להתחיל.',
        ],
    ],

    'filter' => [
        'kind' => [
            'all' => 'הכול',
            'installments' => 'תשלומים',
            'recurring' => 'מנוי חוזר',
        ],
    ],

    'detail' => [
        'remaining_of_total' => 'יתרה :balance מתוך :total',
        'every_frequency' => 'כל :frequency',
        'plan_items' => 'פריטי התוכנית',
        'billing_schedule' => 'לוח חיובים',
        'deposit' => 'מקדמה',
        'installment_n' => 'תשלום :n',
        'fulfillment_locked' => 'המימוש נעול עד לתשלום מלא',
        'order_released' => 'ההזמנה שוחררה למימוש',
        'payment_ledger' => 'יומן תשלומים',
        'ledger_empty' => 'אין עדיין חיובים.',
        'timeline' => 'ציר זמן',
        'next_cycle' => 'מחזור הבא',
        'started' => 'התחיל',
        'col' => [
            'date' => 'תאריך',
            'context' => 'הקשר',
            'amount' => 'סכום',
            'status' => 'סטטוס',
            'tx' => 'עסקה',
            'sequence' => 'פריט',
        ],
    ],
];
