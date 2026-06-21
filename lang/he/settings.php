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

        // מתג סביבה.
        'environment' => 'סביבה',
        'env_production' => 'ייצור',
        'env_sandbox' => 'בדיקות (Sandbox)',

        // חיבור וזיהוי אוטומטי.
        'connect' => 'התחברות',
        'connect_need_creds' => 'הזינו תחילה מפתח API ומפתח סודי.',
        'connect_found' => 'נמצאו :count מסופים בחשבון ה-PayPlus שלכם.',
        'connect_failed' => 'לא ניתן להתחבר ל-PayPlus: :reason',
        'pages_failed' => 'לא ניתן לטעון עמודי תשלום: :reason',
        'discovery_heading' => 'מסוף ועמוד תשלום',
        'discovery_intro' => 'זוהו מחשבון ה-PayPlus שלכם. בחרו אחד אם יש לכם כמה.',
        'terminal' => 'מסוף',
        'terminal_inactive' => 'לא פעיל',
        'payment_page' => 'עמוד תשלום',
        'connected_label' => 'מחובר אל',
        'advanced' => 'מתקדם',
        'advanced_intro' => 'אופציונלי. סוד ה-Webhook אינו ניתן לזיהוי דרך ה-API — הדביקו אותו רק אם PayPlus סיפק לכם אחד.',
        'reason' => [
            'auth' => 'מפתח API או מפתח סודי שגויים',
            'transport' => 'שגיאת רשת או שרת',
            'malformed' => 'תגובה לא צפויה',
        ],
    ],

    'validation' => [
        'required_field' => 'שדה חובה.',
    ],
];
