<?php

// מסך מוצרים (חבילת עבודה W1, שלב E). מראה את ה-LIST + DETAIL + מגירת
// "עריכת תוכנית מנוי". משקף 1:1 את lang/en/products.php — מפתח he חסר חוסם שחרור.
return [

    // --- כותרת הרשימה / באנר ---
    'title' => 'מוצרים',
    'plural' => 'מוצרים',
    'singular' => 'מוצר',
    'markets_banner' => 'התוכניות והתמחור כאן חלים על השוק הראשי שלך. התאמות לכל שוק יגיעו בקרוב.',
    'refresh' => 'רענון מוצרים',
    'refreshed' => 'מרענן מוצרים מ-Shopify — הפעולה רצה ברקע.',
    'refreshed_one' => 'המוצר רוענן.',

    // --- עמודות הרשימה ---
    'col' => [
        'product' => 'מוצר',
        'shopify_status' => 'סטטוס Shopify',
        'online_store' => 'חנות מקוונת',
        'purchase_types' => 'סוגי רכישה',
        'plans' => 'מס׳ תוכניות',
        'sku' => 'מק״ט',
        'updated' => 'עודכן',
    ],
    'variants_count' => '{1} וריאציה אחת|[2,*] :count וריאציות',
    'plans_count' => '{0} אין תוכניות|{1} תוכנית אחת|[2,*] :count תוכניות',

    // --- תגי סטטוס מוצר ---
    'status' => [
        'active' => 'פעיל',
        'draft' => 'טיוטה',
        'unlisted' => 'לא רשום',
    ],
    'online' => [
        'published' => 'פורסם',
        'unpublished' => 'לא פורסם',
    ],

    // --- תגי סוג רכישה (נגזרים מתבניות התוכנית) ---
    'purchase' => [
        'one_time' => 'חד-פעמי',
        'subscription' => 'מנוי',
        'none' => 'אין אפשרויות רכישה',
    ],

    // --- מסננים ---
    'filter' => [
        'product_status' => 'סטטוס מוצר',
        'online_status' => 'סטטוס חנות מקוונת',
        'all' => 'הכול',
        'has_plans' => 'יש תוכניות',
        'has_plans_yes' => 'יש תוכניות',
        'has_plans_no' => 'אין תוכניות',
        'purchase_types' => 'סוגי רכישה',
        'search_placeholder' => 'חיפוש מוצרים…',
    ],

    // --- מצבים ריקים ---
    'empty' => [
        'first_run' => 'אין עדיין מוצרים. רענן מ-Shopify כדי לייבא את הקטלוג.',
        'no_results' => 'אין מוצרים התואמים לחיפוש או למסננים.',
    ],

    // --- עמוד פירוט ---
    'detail' => [
        'back' => 'חזרה למוצרים',
        'product_details' => 'פרטי המוצר',
        'view_in_shopify' => 'הצג ב-Shopify',
        'price' => 'מחיר',
        'sku' => 'מק״ט',
        'no_sku' => 'אין מק״ט',
        'variants_heading' => 'וריאציות ותוכניות',
        'all_variants' => 'כל הוריאציות',
        'variant' => 'וריאציה',
        'add_subscription_plan' => 'הוספת תוכנית מנוי',
        'add_one_time_plan' => 'הוספת תוכנית חד-פעמית',
        'no_plans' => 'אין עדיין תוכניות על וריאציה זו.',
        'one_time_label' => 'חד-פעמי',
        'subscription_label' => 'מנוי',
        'edit_plan' => 'עריכת תוכנית',
        'move_up' => 'הזז למעלה',
        'move_down' => 'הזז למטה',
        'plan_created' => 'נוספה תוכנית טיוטה — הגדר אותה כעת.',

        // מטא של שורת תוכנית
        'ship_every' => 'משלוח כל :count :unit',
        'discount_pct' => ':value% הנחה',
        'discount_fixed' => ':value הנחה',
        'no_discount' => 'אין הנחה',
        'channels_label' => 'ערוצים',

        // עמודת צד
        'side' => [
            'title' => 'פרטים',
            'product_id' => 'מזהה מוצר',
            'variant_id' => 'מזהה וריאציה',
            'shopify_status' => 'סטטוס Shopify',
            'online_store' => 'חנות מקוונת',
            'last_updated' => 'עודכן לאחרונה',
            'tags' => 'תגיות',
            'no_tags' => 'אין תגיות',
            'collection' => 'קטגוריה',
            'collection_placeholder' => 'לא משויך',
        ],
    ],

    // --- תוויות יחידת תדירות חיוב (יחיד; בשימוש עם :count ב-ship_every) ---
    'unit' => [
        'daily' => 'יום',
        'weekly' => 'שבוע',
        'biweekly' => 'שבועיים',
        'monthly' => 'חודש',
        'quarterly' => 'רבעון',
        'yearly' => 'שנה',
    ],

    // --- מגירת "עריכת תוכנית מנוי" ---
    'plan_drawer' => [
        'title' => 'עריכת תוכנית מנוי',
        'title_one_time' => 'עריכת תוכנית חד-פעמית',
        'subtitle' => 'הגדר כיצד לקוחות נרשמים למנוי על מוצר זה.',
        'close' => 'סגור',
        'cancel' => 'ביטול',
        'save' => 'שמירה',
        'saved' => 'התוכנית נשמרה.',

        'type_label' => 'סוג',
        'type_subscription' => 'תוכנית מנוי',
        'type_one_time' => 'רכישה חד-פעמית',

        'ship_label' => 'שלח מוצר זה כל',
        'frequency_unit' => 'תדירות',

        'offer_discount' => 'הצע הנחה על תוכנית זו',
        'discount_label' => 'הנחה',

        'plan_name_label' => 'שם התוכנית (מוצג ללקוחות)',
        'plan_name_placeholder' => 'לדוגמה: הירשם וחסוך',

        'price_summary' => ':price כל :count :unit',
        'price_summary_single' => ':price כל :unit',

        'schedule_heading' => 'לוח חיובים וניתוק',
        'charge_on_label' => 'חיוב לקוחות ב-',
        'charge_on_signup' => 'בעת הרשמת הלקוח',
        'charge_on_day' => 'יום :day בחודש',
        'expire_label' => 'סיום לאחר מספר חיובים',
        'expire_count_label' => 'מספר חיובים',

        'channels_heading' => 'ערוצים',
        'channels_hint' => 'היכן ניתן להציע תוכנית זו.',
        'channel' => [
            'storefront_widget' => 'יישומון חנות',
            'customer_portal' => 'פורטל לקוחות',
            'merchant_portal' => 'פורטל סוחר',
            'api' => 'API',
        ],

        'status_label' => 'סטטוס',
        'status_active' => 'פעיל',
        'status_draft' => 'טיוטה',
    ],
];
