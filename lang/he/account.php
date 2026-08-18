<?php

/*
 * האזור האישי של הלקוח — המראה העברית של lang/en/account.php.
 * המפתחות חייבים להישאר זהים אחד לאחד; מפתח שקיים כאן ולא שם (או להפך) יגיע
 * ללקוח כמחרוזת מפתח גולמית.
 */

return [

    'ui' => [
        'welcome_heading' => 'האזור האישי שלי',
        'welcome_subtext' => 'המנויים, ההטבות וההזמנות שלך במקום אחד.',
        'subscriptions_heading' => 'המנויים שלי',
        'upcoming_heading' => 'מה מחכה לך',
        'benefits_heading' => 'ההטבות שלי',
        'loyalty_heading' => 'מועדון הלקוחות',
        'orders_heading' => 'היסטוריית הזמנות',
        'documents_heading' => 'חשבוניות וקבלות',
        'profile_heading' => 'הפרטים שלי',
        'addresses_heading' => 'כתובות',
        'support_heading' => 'צריכים עזרה?',

        'empty_subscriptions' => 'אין לך עדיין מנויים פעילים.',
        'empty_upcoming' => 'אין כרגע שום דבר מתוזמן.',

        'next_charge' => 'החיוב הבא',
        'every' => 'כל',
        'status' => 'סטטוס',
        'payment_method' => 'אמצעי תשלום',
        'no_card' => 'אין כרטיס שמור',
        'paid_of' => 'שולם מתוך',
        'remaining' => 'נותר לתשלום',
        'payments_heading' => 'תשלומים',

        'action_pause' => 'השהיית המנוי',
        'action_resume' => 'חידוש המנוי',
        'action_cancel' => 'ביטול המנוי',
        'action_skip' => 'דילוג על המשלוח הבא',
        'action_reschedule' => 'שינוי תאריך',
        'action_items' => 'עריכת המוצרים',
        'action_update_card' => 'עדכון כרטיס',
        'confirm_cancel' => 'לבטל את המנוי? אי אפשר לשחזר את הפעולה.',

        'points_balance' => 'יתרת נקודות',
        'points_worth' => 'שווה',
        'tier' => 'דרגה',

        'sign_in_prompt' => 'התחברו כדי לראות את המנויים וההטבות שלכם.',
        'sign_in_cta' => 'התחברות',

        'saved' => 'נשמר',
        'failed' => 'הפעולה לא הצליחה. נסו שוב.',
        'loading' => 'טוען…',
    ],

    /*
     * מוצג בחנות, לא באזור האישי: החנות מרשה מנוי אחד ללקוח, וללקוח הזה כבר יש.
     * התוסף הופך את תווית הקישור לקישור לאזור שלו — הוא יודע את הכתובת, אנחנו לא.
     */
    'purchase' => [
        'blocked' => 'כבר יש לך מנוי פעיל, ולכן לא ניתן להוסיף מנוי נוסף. אפשר לשנות או לבטל את המנוי הקיים באזור האישי.',
        'blocked_link' => 'למנוי שלי',
    ],

    /*
     * תדירות החיוב כמשפט. היחידה היא מחרוזת בחירה (יחיד|רבים) כי בעברית היחידה
     * מתאימה את עצמה למספר — "כל חודש" מול "כל 3 חודשים".
     */
    'cycle' => [
        'every' => 'כל :unit',
        'every_n' => 'כל :count :unit',

        'unit' => [
            'daily' => 'יום|ימים',
            'weekly' => 'שבוע|שבועות',
            'biweekly' => 'שבועיים|שבועיים',
            'monthly' => 'חודש|חודשים',
            'quarterly' => 'רבעון|רבעונים',
            'yearly' => 'שנה|שנים',
        ],
    ],

    /* סטטוסים של תוכנית ושל תשלום חולקים שק אחד — ראו lang/en/account.php. */
    'status' => [
        'draft' => 'טיוטה',
        'awaiting_first_payment' => 'ממתין לתשלום הראשון',
        'active' => 'פעיל',
        'paused' => 'מושהה',
        'failed' => 'החיוב נכשל',
        'completed' => 'הושלם',
        'cancelled' => 'בוטל',
        'pending' => 'ממתין',
        'succeeded' => 'שולם',
        'retry_scheduled' => 'ננסה שוב',
        'refunded' => 'הוחזר',
    ],

    /* הטבות שיש ללקוח עכשיו — ראו AccountPresenter::activeBenefits. */
    'active' => [
        'intro_price' => 'אתם משלמים :now במקום :was',
        'intro_left' => 'עוד :count הזמנות במחיר הזה',
    ],

    'benefit' => [
        'next_delivery' => 'המשלוח הבא',
        'next_order_extra' => 'נוסף להזמנה הבאה שלך',
        'intro_ending' => 'מחיר ההיכרות מסתיים — המחיר יהיה',
        'plan_completes' => 'התשלום האחרון — ואז זה שלך במלואו',
        'birthday_points' => 'נקודות יום הולדת',
        'tier_progress' => 'נותר עד הדרגה הבאה',
        'redeem_ready' => 'אפשר לממש',
    ],

    'result' => [
        'pause' => 'המנוי הושהה.',
        'resume' => 'המנוי חודש.',
        'cancel' => 'המנוי בוטל.',
        'skip' => 'דילגנו על המשלוח הבא.',
        'reschedule' => 'התאריך עודכן.',
        'items' => 'ההזמנה הבאה עודכנה.',
    ],

    'login' => [
        'heading' => 'כניסה עם קוד',
        'intro' => 'נשלח לכם קוד חד-פעמי ב-SMS או במייל.',
        'email_label' => 'כתובת מייל',
        'phone_label' => 'מספר נייד',
        'code_label' => 'קוד אימות',
        'send' => 'שליחת קוד',
        'verify' => 'כניסה',
        'resend' => 'שליחת קוד נוסף',
        'sent' => 'אם יש חשבון תואם, הקוד בדרך.',
        'rejected' => 'הקוד שגוי.',
        'expired' => 'הקוד פג. בקשו קוד חדש.',
        'exhausted' => 'יותר מדי ניסיונות. בקשו קוד חדש.',
    ],

    'sms' => [
        'login_code' => 'הקוד שלך הוא :code. תקף ל-:minutes דקות.',
    ],

    'sample' => [
        'name' => 'דנה',
        'product' => 'מארז הקפה החודשי',
    ],

    'admin' => [
        'title' => 'האזור האישי',
        'nav' => 'האזור האישי',
        'subheading' => 'מה הלקוחות רואים בחשבון שלהם, ואיך זה נראה.',

        'tab' => [
            'sections' => 'אזורים',
            'appearance' => 'עיצוב',
            'banners' => 'באנרים צדדיים',
            'login' => 'כניסה',
            'copy' => 'טקסטים',
        ],

        'sections' => [
            'help' => 'גררו כדי לשנות סדר. כיבוי אזור מסתיר אותו מכל הלקוחות.',
            'locked' => 'מוצג תמיד — לקוח חייב להיות מסוגל להגיע למנוי שלו.',
            'label' => [
                'welcome' => 'כותרת פתיחה',
                'subscriptions' => 'מנויים',
                'upcoming' => 'מה מחכה לך (ציר ההטבות)',
                'benefits' => 'הטבות',
                'loyalty' => 'מועדון הלקוחות',
                'orders' => 'היסטוריית הזמנות',
                'downloads' => 'ההורדות שלי (טאב של ווקומרס)',
                'documents' => 'חשבוניות וקבלות',
                'profile' => 'הפרטים שלי',
                'addresses' => 'כתובות',
                'support' => 'תמיכה',
            ],
        ],

        'appearance' => [
            'locale' => 'שפה',
            'locale_help' => 'השפה שבה הלקוחות שלכם קוראים את האזור — כולל קודי הכניסה שנשלחים אליהם.',
            'locale_option' => [
                'auto' => 'לפי שפת האתר',
                'he' => 'עברית',
                'en' => 'אנגלית',
            ],
            'accent' => 'צבע ראשי',
            'accent_text' => 'צבע הטקסט על הראשי',
            'theme' => 'ערכת נושא',
            'radius' => 'פינות',
            'density' => 'ריווח',
            'card' => 'סגנון כרטיס',
            'font_note' => 'הפונט נלקח אוטומטית מתבנית החנות, כך שהאזור תמיד מתאים לאתר שלכם.',
            'theme_option' => ['light' => 'בהיר', 'dark' => 'כהה', 'auto' => 'לפי המכשיר של הלקוח'],
            'radius_option' => ['sharp' => 'ישרות', 'soft' => 'מעוגלות', 'pill' => 'עגולות'],
            'density_option' => ['compact' => 'צפוף', 'comfortable' => 'מרווח'],
            'card_option' => ['flat' => 'שטוח', 'outlined' => 'עם מסגרת', 'raised' => 'עם צל'],
        ],

        'banners' => [
            'help' => 'עד שלושה באנרים. בחרו לכל אחד היכן הוא יופיע ומי יראה אותו. באנר חייב כותרת או תמונה כדי להופיע.',
            'heading' => 'כותרת',
            'subtext' => 'טקסט משנה',
            'image_url' => 'כתובת תמונה',
            'link_url' => 'מוביל אל',
            'enabled' => 'הצגה',
            'https_only' => 'חייב להתחיל ב-https://',
            'placement' => 'מיקום',
            'placement_option' => [
                'rail' => 'סרגל צד',
                'top' => 'ראש העמוד',
            ],
            'audience' => 'קהל יעד',
            'audience_option' => [
                'everyone' => 'כולם',
                'subscribers' => 'מנויים',
                'non_subscribers' => 'לא-מנויים',
            ],
        ],

        'login' => [
            'enabled' => 'אפשרו ללקוחות להתחבר עם קוד חד-פעמי',
            'enabled_help' => 'מוסיף כניסה ללא סיסמה לדף החשבון. הלקוחות עדיין יכולים להשתמש בסיסמה.',
            'channel' => 'שליחת הקוד דרך',
            'channel_option' => ['email' => 'מייל', 'sms' => 'SMS', 'both' => 'מייל ו-SMS'],
            'sms_heading' => 'חשבון SMS (019)',
            'sms_help' => 'הקודים נשלחים מחשבון 019 שלכם, כך שהם נושאים את שם השולח שלכם. בלעדיו יעבוד רק מייל.',
            'sms_enabled' => 'שליחה ב-SMS',
            'sms_username' => 'שם משתמש ב-019',
            'sms_token' => 'טוקן API של 019',
            'sms_sender' => 'שם השולח',
            'sms_sender_help' => 'עד 11 אותיות או ספרות, בלי רווחים — זה מה שיופיע כשולח.',
            'sms_incomplete' => 'כניסה ב-SMS עדיין לא מוכנה',
            'sms_incomplete_help' => 'בחרתם לשלוח קודים ב-SMS, אבל חשבון 019 לא מלא. כל עוד שם המשתמש, הטוקן ושם השולח לא מולאו — לקוח שיבקש קוד ב-SMS פשוט לא יקבל אותו.',
            'google_client_id' => 'כניסה עם Google — מזהה לקוח OAuth',
            'google_client_id_help' => 'הדביקו את ה-Client ID מפרויקט Google Cloud שלכם כדי להציג כפתור "כניסה עם Google". כתובת החנות חייבת להופיע ב-Authorized JavaScript origins של אותו OAuth client. השאירו ריק כדי להסתיר את הכפתור.',
            'google_client_id_invalid' => 'זה לא נראה כמו מזהה לקוח OAuth של Google (הוא מסתיים ב-apps.googleusercontent.com.).',
        ],

        'copy' => [
            'welcome_heading' => 'כותרת הפתיחה',
            'welcome_subtext' => 'טקסט הפתיחה',
            'support_email' => 'מייל תמיכה',
            'support_url' => 'עמוד תמיכה',
            'blank_help' => 'השאירו ריק כדי להשתמש בנוסח הדיפולטיבי.',
        ],

        'preview' => [
            'heading' => 'תצוגה מקדימה',
            'help' => 'בדיוק מה שהלקוחות רואים — אותו קובץ עיצוב, אותו מנוע רינדור.',
        ],

        'saved' => 'האזור האישי נשמר.',
    ],

];
