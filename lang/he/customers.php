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

    // פרטי הקשר נקראים מהחנות ונכתבים ישירות אליה — ה־SaaS לא שומר עותק.
    'contact' => [
        'heading' => 'פרטי קשר',
        'edit' => 'עריכה',
        'save' => 'שמירה בחנות',
        'cancel' => 'ביטול',
        'saved' => 'נשמר בחנות שלכם.',
        'save_failed' => 'החנות שלכם לא קיבלה את השינוי.',
        'name' => 'שם',
        'first_name' => 'שם פרטי',
        'last_name' => 'שם משפחה',
        'phone' => 'טלפון',
        'address' => 'כתובת למשלוח',
        'country_hint' => 'קוד מדינה בן שתי אותיות, לדוגמה IL.',
        'reason' => [
            'empty' => 'בחנות שלכם עדיין אין פרטי קשר ללקוח הזה.',
            'guest' => 'הלקוח הזה ביצע רכישה כאורח, ולכן אין בחנות חשבון לערוך.',
            'access_pending' => 'Shopify עדיין לא העניקה לאפליקציה גישה לפרטי לקוחות. אשרו את שדה Address תחת Protected customer data בפרטנר דשבורד, ואז חברו מחדש את האפליקציה.',
            'unavailable' => 'לא הצלחנו להגיע לחנות כדי לקרוא את הפרטים.',
        ],
    ],
];
