<?php

// תוויות ניווט בסרגל הצד (docs/ux/01-navigation.md). שיקוף של lang/en/nav.php.
return [
    // ראשי + עלים
    'home' => 'בית',
    'analytics' => 'אנליטיקה',
    'customers' => 'לקוחות',
    'subscriptions' => 'מנויים',
    'orders' => 'הזמנות',
    'order_errors' => 'שגיאות הזמנה',
    'products' => 'מוצרים',
    'discounts' => 'הנחות',
    'cross_sell_upsell' => 'מכירה צולבת ושדרוג',
    'post_purchase_offers' => 'הצעות לאחר רכישה',
    'storefront' => 'חזית החנות',
    'settings' => 'הגדרות',
    'payments' => 'תשלומים',
    'invoices' => 'חשבוניות',
    'shopify_subscriptions' => 'מנויי Shopify',

    // עלים נוספים / עתידיים
    'segments' => 'פלחים',
    'credits' => 'זיכויים',
    'loyalty' => 'מועדון לקוחות',
    'churn_tools' => 'כלי שימור',
    'sms' => 'SMS',
    'email' => 'אימייל',
    'tools_apps' => 'כלים ואפליקציות',

    // כותרות קבוצה
    'group' => [
        'platform' => 'פלטפורמה',
        'customers' => 'לקוחות',
        'products' => 'מוצרים',
        'payments' => 'תשלומים',
        'upsell' => 'מכירה צולבת ושדרוג',
        'settings' => 'הגדרות',
    ],

    // וידג'ט החנות
    'store_widget' => [
        'upgrade' => 'שדרוג',
        'switch_shop' => 'החלפת חנות',
    ],
    'support' => [
        'chat' => "צ'אט תמיכה",
    ],
    // the store card at the foot of the sidebar
    'platform_name' => [
        'shopify' => 'Shopify',
        'woocommerce' => 'WooCommerce',
    ],

    // the top bar (approved sketch): search, bell, help, language pill
    'search' => [
        'label' => 'חיפוש',
        'placeholder' => 'חיפוש לקוחות, מנויים, הזמנות…',
        'group' => [
            'customers' => 'לקוחות',
            'subscriptions' => 'מנויים',
            'orders' => 'הזמנות',
        ],
        'order' => 'הזמנה #:number',
    ],
    'alerts' => [
        'label' => 'התראות',
        'label_pending' => 'התראות — יש פריטים שדורשים טיפול',
        'heading' => 'דורש טיפול',
        'failed_charges' => 'חיובים שלא נגבו',
        'invoices' => 'חשבוניות שדורשות טיפול',
        'empty' => 'אין כרגע משהו שדורש טיפול.',
    ],
    'help' => 'עזרה — מייל לתמיכה של LETS',
    'language' => 'שפה',
    'locale_short' => [
        'en' => 'EN',
        'he' => 'עב',
    ],
];
