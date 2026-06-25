<?php

// מחרוזות לוח התצפיתיות (docs/ux/10 §observability, ARCHITECTURE.md §6.6).
// משקף את lang/en/observability.php אחד-לאחד.
return [
    'title' => 'תצפיתיות',
    'nav' => 'תצפיתיות',

    'scope' => [
        'platform' => 'כל החנויות (מבט-על)',
        'shop' => 'חנות זו',
    ],

    'window' => [
        '24h' => '24 השעות האחרונות',
        '7d' => '7 הימים האחרונים',
    ],

    'kpi' => [
        'success_rate' => 'שיעור חיובים מוצלחים',
        'failed' => 'חיובים שנכשלו',
        'refunded' => 'זיכויים',
        'total_charged' => 'סך החיובים',
    ],

    'health' => [
        'title' => 'תורים ומתזמן',
    ],

    'scheduler' => [
        'label' => 'מתזמן החיובים',
        'ago' => '(לפני :minutes דקות)',
        'never' => 'טרם בוצעה הרצה',
    ],

    'plans' => [
        'title' => 'תוכניות לפי סטטוס',
    ],

    'failures' => [
        'title' => 'דורש טיפול — חיובים שנכשלו לאחרונה',
        'empty' => 'אין חיובים שנכשלו',
        'empty_body' => 'כל ניסיונות החיוב האחרונים הצליחו.',
        'no_reason' => 'לא נרשמה סיבה',
        'col' => [
            'shop' => 'חנות',
            'context' => 'הקשר',
            'amount' => 'סכום',
            'reason' => 'סיבה',
            'when' => 'מתי',
        ],
    ],
];
