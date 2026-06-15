<?php

// לקוחות — רשימה ופירוט (docs/ux/20-customers.md). שיקוף של lang/en/customers.php.
return [
    'list' => [
        'title' => 'לקוחות',
        'search_placeholder' => 'חיפוש שם או אימייל',
        'col' => [
            'customer' => 'לקוח',
            'email' => 'אימייל',
            'active_subs' => 'מנויים פעילים',
            'payment_status' => 'תשלום',
        ],
        'empty' => [
            'first_run' => 'אין עדיין לקוחות. הם יופיעו לאחר ההזמנה הראשונה בחנות.',
        ],
    ],

    'detail' => [
        'kpi' => [
            'subscription_spend' => 'הוצאה על מנויים',
            'orders' => 'הזמנות',
            'streak' => 'רצף',
        ],
        'subscriptions_title' => 'מנויים',
        'shipping_address' => 'כתובת למשלוח',
        'no_subscriptions' => 'ללקוח זה אין תוכניות פעילות.',
        'upcoming_orders' => 'הזמנות קרובות',
        'recent_orders' => 'הזמנות אחרונות',
        'timeline' => 'ציר זמן',
        'timeline_empty' => 'טרם נרשמה פעילות.',
        'panel' => [
            'overview' => 'סקירת לקוח',
            'comm_prefs' => 'העדפות תקשורת',
            'payment_methods' => 'אמצעי תשלום',
            'segments' => 'פלחים',
            'tags' => 'תגיות Shopify',
            'credits' => 'זיכויים',
        ],
        'overview' => [
            'name' => 'שם',
            'email' => 'אימייל',
            'customer_id' => 'מזהה לקוח',
            'since' => 'לקוח מאז',
        ],
        'no_payment_methods' => 'אין אמצעי תשלום שמור.',
        'action' => [
            'open_portal' => 'העתקת קישור לפורטל',
            'view_in_shopify' => 'צפייה ב-Shopify',
        ],
    ],
];
