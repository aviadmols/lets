<?php

// מודול האנליטיקה — טקסטים משותפים למעטפת ולרכיבים. מראה של lang/en/analytics.php.
// מנויים = אנשים (subscribers), מינויים = תוכניות (subscriptions).
return [
    'title' => 'אנליטיקה',
    'sections_label' => 'חלקי האנליטיקה',
    'export' => 'ייצוא',
    'export_unavailable' => 'אין עדיין מה לייצא במסך הזה.',
    'view_report' => '← לדוח המלא',
    'other' => 'אחר',

    'sections' => [
        'subscribers' => 'מנויים',
        'cohorts' => 'קוהורטות',
        'payments' => 'תשלומים',
        'products' => 'מוצרים',
        'upsells' => 'שדרוגים',
        'cancellations' => 'ביטולים',
        'forecast' => 'תחזית',
        'reports' => 'דוחות',
    ],

    'subtabs' => [
        'subscribers' => [
            'overview' => 'סקירה',
            'acquisition' => 'גיוס',
            'order_funnel' => 'משפך הזמנות',
            'revenue' => 'הכנסות',
            'lifetime_value' => 'ערך חיי לקוח',
        ],
        'cohorts' => ['overview' => 'קוהורטות'],
        'payments' => [
            'overview' => 'סקירה',
            'recovery' => 'שחזור',
            'failures' => 'כשלים',
            'upcoming' => 'תשלומים קרובים',
        ],
        'products' => [
            'overview' => 'סקירה',
            'revenue' => 'הכנסות',
            'churn' => 'נטישה ושימור',
        ],
        'upsells' => [
            'added' => 'נוספו',
            'sold' => 'נמכרו',
        ],
        'cancellations' => [
            'overview' => 'סקירה',
            'saves' => 'הצלות',
            'order_wise' => 'נטישה לפי הזמנה',
            'risk' => 'ניתוח סיכון',
        ],
        'forecast' => ['overview' => 'תחזית'],
        'reports' => [
            'reports' => 'דוחות',
            'exports' => 'ייצואים',
        ],
    ],

    'platform' => [
        'shopify' => 'Shopify',
        'woocommerce' => 'WooCommerce',
    ],

    'range' => [
        '7d' => '7 הימים האחרונים',
        '30d' => '30 הימים האחרונים',
        '90d' => '90 הימים האחרונים',
        'mtd' => 'מתחילת החודש',
        'ytd' => 'מתחילת השנה',
        'custom' => 'טווח מותאם',
        'from' => 'מתאריך',
        'to' => 'עד תאריך',
        'apply' => 'החלה',
    ],

    'compare' => [
        'label' => 'השוואה: :mode',
        'previous_period' => 'התקופה הקודמת',
        'previous_year' => 'השנה הקודמת',
        'none' => 'ללא',
        'compared_with' => 'בהשוואה ל-:span',
        'vs_previous_days' => 'לעומת :days הימים הקודמים',
        'vs_last_year' => 'לעומת אותה תקופה אשתקד',
        'vs_span' => 'לעומת :span',
    ],

    'filters' => [
        'country' => 'מדינה',
        'products' => 'מוצרים',
        'plans' => 'תוכניות מנוי',
        'frequencies' => 'תדירויות',
        'country_hint' => 'LETS אינה שומרת מדינת לקוח — כל החנויות מוכרות כיום בישראל.',
        'n_selected' => ':count נבחרו',
        'clear' => 'ניקוי :filter',
        'none_available' => 'אין עדיין לפי מה לסנן.',
    ],

    'grain' => [
        'label' => 'רזולוציה',
        'daily' => 'יומי',
        'weekly' => 'שבועי',
        'monthly' => 'חודשי',
    ],

    'frequency' => [
        'daily' => 'יומי',
        'weekly' => 'שבועי',
        'monthly' => 'חודשי',
        'yearly' => 'שנתי',
        'every_days' => 'כל :count ימים',
        'every_weeks' => 'כל :count שבועות',
        'every_months' => 'כל :count חודשים',
        'every_years' => 'כל :count שנים',
    ],

    'selling_plan' => [
        'none' => 'ללא תוכנית מנוי',
        'shopify' => 'מינויי Shopify',
        'unnamed' => 'תוכנית #:id',
    ],

    'movement' => [
        'subscriber' => [
            'new' => 'חדשים',
            'reactivated' => 'הופעלו מחדש',
            'resumed' => 'חודשו',
            'paused' => 'הושהו',
            'cancelled' => 'נטשו',
            'expired' => 'פגו',
        ],
        'subscription' => [
            'new' => 'חדשים',
            'reactivated' => 'הופעלו מחדש',
            'resumed' => 'חודשו',
            'paused' => 'הושהו',
            'cancelled' => 'בוטלו',
            'expired' => 'פגו',
        ],
    ],

    'delta' => [
        'points' => ':value נק׳',
        'sr_good' => '(שינוי לטובה)',
        'sr_bad' => '(שינוי לרעה)',
        'sr_flat' => '(ללא שינוי)',
    ],

    'empty' => [
        'no_data_title' => 'אין נתונים בתקופה הזו',
        'no_data_body' => 'לא קרה כאן דבר בתאריכים שנבחרו. נסו טווח ארוך יותר.',
        'not_tracked_title' => 'עדיין לא נמדד',
        'not_tracked_body' => 'LETS עדיין לא שומרת את מה שנדרש לכך, ולכן לא מוצג כאן דבר במקום הערכה.',
    ],

    'table' => [
        'period' => 'תקופה',
        'cohort' => 'קוהורטה',
        'size' => 'מנויים',
    ],

    'placeholder' => [
        'badge' => 'בבנייה',
        'title' => 'המסך הזה בדרך',
        'body' => 'הוא חלק מעיצוב האנליטיקה שאושר ונבנה עכשיו. הלשוניות, התאריכים והסינונים שלמעלה כבר עובדים.',
        'spec' => 'אפיון :spec',
        'sketch' => 'סקיצה :sketch',
    ],
];
