<?php

// מחרוזות תחום החיוב/מנויים. שיקוף של כל מפתח מ-lang/en/billing.php.
return [
    // סוגי תוכניות
    'plan_kind' => [
        'installments' => 'תשלומים',
        'recurring' => 'מנוי מתחדש',
    ],

    // סטטוסים של תוכנית
    'status' => [
        'draft' => 'טיוטה',
        'awaiting_first_payment' => 'ממתין לתשלום ראשון',
        'active' => 'פעיל',
        'paused' => 'מושהה',
        'completed' => 'הושלם',
        'failed' => 'נכשל',
        'cancelled' => 'בוטל',
    ],

    // הקשרי חיוב
    'charge_context' => [
        'deposit' => 'מקדמה',
        'installment' => 'תשלום',
        'recurring' => 'חיוב מתחדש',
        'upsell' => 'שדרוג לאחר רכישה',
        'retry' => 'ניסיון חוזר',
        'manual' => 'ידני',
    ],

    // תוויות נפוצות
    'next_charge' => 'חיוב הבא',
    'remaining_balance' => 'יתרה לתשלום',
    'total_amount' => 'סכום כולל',
    'paid_amount' => 'סכום ששולם',
];
