<?php

// אנליטיקה › ביטולים › סקירה. מראה של lang/en/analytics/cancellations_overview.php.
// מנויים = אנשים, מינויים = תוכניות.
return [
    'section' => [
        'subscriber' => 'נטישת מנויים',
        'subscription' => 'ביטולי מינויים',
    ],

    'kpi' => [
        'churn_rate' => 'שיעור נטישה',
        'lost' => 'מנויים שעזבו',
        'zero_day' => 'תרומת נטישה ביום ההצטרפות',
        'upcoming' => 'תרומת ההזמנה הקרובה',
        'cancellation_rate' => 'שיעור ביטול',
        'cancelled' => 'מינויים שבוטלו',
        'orders_before' => 'הזמנות לפני ביטול',
        'mrr_lost' => 'MRR שאבד',
    ],

    'caption' => [
        'of_start' => ':n מתוך :start פעילים בתחילת התקופה',
        'zero_day' => ':n מתוך :lost עזבו ביום שהצטרפו',
        'orders_avg' => 'ממוצע הזמנות שהושלמו',
        'mrr_share' => ':share מה-MRR הפעיל בתחילת התקופה',
    ],

    'trend' => [
        'title' => 'מגמות נטישה',
        'metric_label' => 'מדד',
        'subtitle' => [
            'weekly' => ':n השבועות האחרונים',
            'monthly' => ':n החודשים האחרונים',
        ],
        'metric' => [
            'churn' => 'שיעור נטישה',
            'cancellation' => 'שיעור ביטול',
            'orders' => 'הזמנות לפני ביטול',
            'mrr' => 'MRR שאבד',
        ],
        'series' => [
            'lost' => 'מנויים שעזבו',
            'churn_rate' => 'שיעור נטישה (ציר שמאלי)',
            'cancelled' => 'מינויים שבוטלו',
            'cancellation_rate' => 'שיעור ביטול (ציר שמאלי)',
            'orders_before' => 'ממוצע הזמנות לפני ביטול (ציר שמאלי)',
            'mrr_lost' => 'MRR שאבד',
        ],
    ],

    'products' => [
        'title' => '10 המוצרים המובילים לפי MRR שבוטל',
        'subtitle' => 'MRR שבוטל בתקופה · % מה-MRR של המוצר (פעיל היום + שבוטל)',
        'display' => ':mrr · :share',
    ],

    'order_wise' => [
        'title' => 'ביטולים לפי מספר הזמנה',
        'subtitle' => 'לפי הזמנות שהושלמו · % = שבוטלו ÷ (פעילים היום + שבוטלו)',
        'active' => 'מינויים פעילים במספר הזמנות זה',
        'cancelled' => 'בוטלו בתקופה',
        'bucket_plus' => ':n ומעלה',
    ],

    'reasons' => [
        'title' => 'ביטולים לפי סיבה',
        'caption' => 'בוטלו',
        'note' => 'הסיבה שהוקלדה בעת הביטול. ל-LETS עדיין אין רשימת סיבות בתהליך הביטול, ולכן לרוב הביטולים אין סיבה.',
    ],

    'channels' => [
        'title' => 'ביטולים לפי ערוץ',
        'note' => 'היכן בוצע הביטול.',
    ],

    'reason_trend' => [
        'title' => 'מגמת ביטולים לפי סיבה',
        'subtitle' => 'מינויים שבוטלו לפי סיבה',
    ],

    'by_frequency' => ['title' => 'ביטולים לפי תדירות'],
    'by_plan' => ['title' => 'ביטולים לפי תוכנית מכירה'],

    'dimension' => [
        'subtitle' => 'בוטלו בתקופה, מתוך פעילים היום + שבוטלו',
        'row' => ':n / :base · :share',
    ],

    'channel' => [
        'account_area' => 'אזור אישי',
        'customer_portal' => 'פורטל לקוח',
        'customer' => 'לקוח',
        'admin' => 'מנהל',
        'platform' => 'תמיכת LETS',
        'payment_failed' => 'תשלום נכשל',
        'plan_switch' => 'מעבר תוכנית',
        'refund' => 'הזמנה זוכתה',
        'store' => 'ניהול החנות',
        'automatic' => 'אוטומטי',
    ],

    'reason' => [
        'none' => 'ללא סיבה',
        'payment_failed' => 'תשלום נכשל',
        'switched' => 'עבר לתוכנית אחרת',
        'refunded' => 'הזמנה זוכתה',
    ],

    'product' => [
        'unknown' => 'מוצר לא ידוע',
        'id' => 'מוצר :id',
    ],

    'export' => [
        'date' => 'בוטל ב',
        'subscription' => 'מינוי',
        'customer' => 'לקוח',
        'product' => 'מוצר',
        'frequency' => 'תדירות',
        'mrr' => 'MRR',
        'orders' => 'הזמנות שהושלמו',
        'channel' => 'ערוץ',
        'reason' => 'סיבה',
    ],
];
