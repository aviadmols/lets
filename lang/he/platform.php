<?php

// מחרוזות מנהל הפלטפורמה (רשימת חנויות/חשבונות + כניסה/יציאה להקשר) — W2.
// שיקוף של כל מפתח מ-lang/en/platform.php.
return [
    // רשימת חנויות / חשבונות
    'shops' => [
        'nav' => 'חנויות',
        'title' => 'חנויות',
        'model' => 'חנות',
        'view' => 'צפייה',
        'empty' => 'אף חנות עדיין לא התקינה את האפליקציה.',
        'col' => [
            'domain' => 'דומיין החנות',
            'name' => 'שם',
            'platform' => 'פלטפורמה',
            'status' => 'סטטוס',
            'plan' => 'תוכנית',
            'payplus' => 'PayPlus',
            'products' => 'מוצרים',
            'active_subs' => 'מנויים פעילים',
            'revenue' => 'הכנסות שעובדו',
            'installed_at' => 'הותקן',
            'uninstalled_at' => 'הוסר',
        ],
    ],

    // תוויות סטטוס חנות (סטטוסים של מודל החנות — נבדלים מסטטוסי תוכנית/יומן)
    'status' => [
        'installed' => 'מותקן',
        'active' => 'פעיל',
        'uninstalled' => 'הוסר',
    ],

    // תוויות פלטפורמת קטלוג
    'platform' => [
        'shopify' => 'Shopify',
        'woocommerce' => 'WooCommerce',
    ],

    // החלפת הקשר — כניסה / יציאה מחנות
    'enter' => [
        'action' => 'כניסה לחנות',
        'entered' => 'צופים כעת ב-:shop. כל הפעולות נרשמות כמנהל פלטפורמה.',
    ],
    'exit' => [
        'action' => 'יציאה',
        'exited' => 'חזרה למצב פלטפורמה.',
    ],

    // באנר "צופים כ-{חנות}" הקבוע
    'banner' => [
        'viewing_as' => 'צופים כ-:shop',
        'note' => 'פועלים מטעם המוכר — כל שינוי נרשם ביומן.',
    ],

    // סקירת חשבון לקריאה בלבד (ViewShop)
    'overview' => [
        'account' => 'חשבון',
        'products' => 'מוצרים',
        'active_subscriptions' => 'מנויים פעילים',
        'revenue' => 'הכנסות שעובדו',
        'recent_activity' => 'פעילות אחרונה',
        'payplus' => 'חיבור PayPlus',
        'shopify' => 'חיבור Shopify',
        'connected' => 'מחובר',
        'not_connected' => 'לא מחובר',
    ],
];
