<?php

// הגדרות (docs/ux/50-settings.md). שיקוף של lang/en/settings.php.
return [
    'title' => 'הגדרות',

    'section' => [
        'payplus' => 'חיבור PayPlus',
        'shopify' => 'Shopify',
        'payment' => 'תשלום',
        'shipping' => 'משלוח',
        'legal' => 'משפטי',
        'order_processing' => 'עיבוד הזמנות',
        'merchant_billing' => 'חיוב סוחר',
        'mail' => 'הגדרות דואר',
        'plan_billing' => 'תוכנית וחיוב',
        'notifications' => 'התראות',
    ],

    'payplus' => [
        'heading' => 'חיבור PayPlus',
        'intro' => 'הזינו את פרטי חשבון ה-PayPlus שלכם. הם נשמרים מוצפנים ומשמשים רק לחנות זו.',
        'api_key' => 'מפתח API',
        'secret_key' => 'מפתח סודי',
        'terminal_uid' => 'מזהה מסוף',
        'cashier_uid' => 'מזהה קופה',
        'payment_page_uid' => 'מזהה עמוד תשלום',
        'base_url' => 'כתובת בסיס API',
        'webhook_secret' => 'סוד Webhook',
        'masked_hint' => 'נשמר. הדביקו ערך חדש כדי להחליף.',
        'test' => 'בדיקת חיבור',
        'test_ok' => 'החיבור הצליח.',
        'test_fail' => 'החיבור נכשל: :reason',
        'save' => 'שמירת פרטים',
        'saved' => 'פרטי PayPlus נשמרו.',
        'status' => [
            'connected' => 'מחובר',
            'not_connected' => 'לא מחובר',
            'error' => 'שגיאת חיבור',
        ],
        'empty' => 'חברו את חשבון PayPlus כדי להתחיל לחייב.',
    ],

    'validation' => [
        'required_field' => 'שדה חובה.',
    ],
];
