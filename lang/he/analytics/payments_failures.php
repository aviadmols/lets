<?php

// Analytics › Payments › Failures — Hebrew. Mirrors lang/en/analytics/payments_failures.php key for key.
return [
    'total' => [
        'title' => 'סך הכישלונות',
        'label' => 'ניסיונות ראשונים שנכשלו',
        'share' => ':rate מתוך :attempts ניסיונות ראשונים',
        'entered' => 'נכנסו לשחזור',
        'recovered' => 'שוחזרו',
        'under' => 'עדיין בתהליך שחזור',
        'lost' => 'אבדו',
    ],

    'sources' => [
        'title' => 'כישלונות לפי מקור תשלום',
        'caption' => 'לפי מקור',
    ],

    'reasons' => [
        'title' => 'סיבות הכישלון המובילות',
        'caption' => 'לפי סיבה',
    ],

    'reason_note' => 'PayPlus מחזירה אותו קוד לכל סירוב, ולכן הסיבות נקראות מהניסוח שלה; כל מה שלא מזוהה נספר כ"אחר".',

    'over_time' => [
        'title' => 'סיבות כישלון לאורך זמן',
        'subtitle' => 'ניסיונות ראשונים שנכשלו בכל תקופה, לפי סיבה',
    ],

    'reason' => [
        'insufficient_funds' => 'אין כיסוי מספיק',
        'blocked' => 'כרטיס חסום',
        'expired' => 'כרטיס לא בתוקף',
        'stolen_lost' => 'דווח כגנוב או אבוד',
        'refused' => 'סירוב של המנפיק',
        'call_issuer' => 'יש להתקשר לחברת האשראי',
        'token_missing' => 'הכרטיס השמור לא נמצא',
        'technical' => 'תקלה טכנית',
        'other' => 'אחר',
    ],

    'export' => [
        'reason' => 'סיבה',
        'failures' => 'ניסיונות ראשונים שנכשלו',
        'share' => 'אחוז',
    ],
];
