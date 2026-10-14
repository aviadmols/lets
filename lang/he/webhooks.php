<?php

/*
| הגדרות → Webhooks (app/Filament/Pages/ManageWebhooks.php). שיקוף של lang/en/webhooks.php.
*/
return [
    'title' => 'Webhooks',
    'intro' => 'שליחת כל הזמנה ששולמה וכל חיוב חוזר — שהצליח או נכשל — כ-JSON לכתובת שלכם (Make, Zapier, n8n, מערכת ERP).',

    'topic' => [
        'order.paid' => 'הזמנה שולמה',
        'recurring_charge.succeeded' => 'חיוב חוזר בוצע',
        'recurring_charge.failed' => 'חיוב חוזר נכשל',
    ],
    'topic_help' => [
        'order.paid' => 'ווקומרס: כל הזמנה באתר, ברגע שהיא שולמה (בטיפול / הושלמה), בכל אמצעי תשלום. שופיפיי: כל הזמנה ששולמה דרך LETS — מקדמה, אפסייל בדף התודה, תוספת מאזור הלקוח.',
        'recurring_charge.succeeded' => 'מחזור של מנוי או תשלום של עסקת תשלומים חויבו בהצלחה.',
        'recurring_charge.failed' => 'מחזור של מנוי או תשלום של עסקת תשלומים נדחו — כולל האם ומתי ננסה שוב.',
    ],

    'col' => [
        'name' => 'שם',
        'topics' => 'שולח',
        'active' => 'פעיל',
        'health' => 'משלוח אחרון',
    ],

    'field' => [
        'name' => 'שם',
        'name_placeholder' => 'למשל: Make — הזמנות לגוגל שיטס',
        'url' => 'כתובת URL',
        'url_help' => 'כתובת ציבורית שמתחילה ב-https://. נשלח אליה JSON חתום ב-POST, בלי לעקוב אחרי הפניות.',
        'topics' => 'אירועים לשליחה',
        'active' => 'פעיל',
        'topic' => 'אירוע',
        'origin' => 'נתונים',
    ],

    'origin' => [
        'real' => 'האירוע האמיתי האחרון שלי (ואם אין — דוגמה)',
        'example' => 'דוגמה מומצאת',
    ],

    'action' => [
        'create' => 'הוספת Webhook',
        'test' => 'שליחת בדיקה',
        'secret' => 'מפתח חתימה',
        'rotate' => 'החלפת מפתח',
        'close' => 'סגירה',
        'copy' => 'העתקה',
        'copied' => 'הועתק',
        'download' => 'הורדת ‎.json',
        'view_json' => 'JSON',
        'resend' => 'שליחה חוזרת',
    ],

    'health' => [
        'ok' => 'נמסר :when',
        'failing' => ':count כשלונות ברצף',
        'never' => 'עוד לא נשלח דבר',
    ],

    'empty' => [
        'title' => 'עדיין אין Webhooks',
        'body' => 'הוסיפו כתובת כדי להתחיל לקבל הזמנות וחיובים חוזרים כ-JSON.',
    ],

    'secret' => [
        'heading' => 'מפתח חתימה',
        'body' => 'השתמשו במפתח הזה כדי לאמת את הכותרת X-Lets-Signature בכל בקשה. שמרו אותו בסוד.',
        'rotate_warning' => 'המפתח הישן מפסיק לעבוד מיד. עדכנו את המערכת המקבלת מיד אחרי ההחלפה.',
    ],

    'signing' => [
        'title' => 'איך הבקשות נחתמות',
        'body' => 'כל בקשה היא POST עם גוף JSON והכותרות X-Lets-Topic, X-Lets-Event-Id ו-X-Lets-Signature. חשבו מחדש את החתימה על הגוף הגולמי עם המפתח שלכם והשוו; דחו חותמת זמן ישנה מ-5 דקות.',
        'retries' => 'משלוח שלא מקבל תשובת 2xx תוך 10 שניות נשלח שוב אחרי דקה, 5 דקות, 30 דקות, שעתיים ו-6 שעות. ה-event_id זהה בכל ניסיון — השתמשו בו כדי להתעלם מכפילויות.',
    ],

    'generator' => [
        'title' => 'JSON לבדיקה',
        'body' => 'צרו דוגמה לכל אירוע כדי לראות בדיוק מה הכתובת שלכם תקבל. הדוגמאות מסומנות "test": true ומקבלות event_id חדש.',
        'generate' => 'יצירה',
        'built_from' => [
            'real' => 'נבנה מהאירוע האמיתי האחרון שלכם.',
            'example' => 'דוגמה מומצאת — עדיין אין אירוע אמיתי מתאים.',
        ],
    ],

    'log' => [
        'title' => 'פניות שנשלחו',
        'intro' => 'כל פנייה שנשלחה, והאם הכתובת שלכם קיבלה אותה (ענתה 2xx). פנייה שלא התקבלה נשלחת שוב אוטומטית, ואפשר גם לשלוח אותה שוב ידנית.',
        'filter' => [
            'all' => 'הכול',
            'received' => 'התקבלו',
            'not_received' => 'לא התקבלו',
        ],
        'order' => 'הזמנה #:number',
        'no_answer' => 'אין תשובה: :error',
        'received_at' => 'התקבל :when',
        'next_attempt' => 'ניסיון הבא :when',
        'sent_body' => 'מה שנשלח',
        'newer' => 'חדשות יותר',
        'older' => 'ישנות יותר',
        'page' => 'עמוד :page',
        'empty' => 'עוד לא נשלח דבר.',
        'test' => 'בדיקה',
        'attempts' => ':count ניסיונות',
        'response_body' => 'תשובה',
        'not_built' => 'עוד לא נבנה — הוא נבנה בניסיון הראשון.',
        'col' => [
            'when' => 'מתי',
            'endpoint' => 'Webhook',
            'subject' => 'הזמנה / לקוח',
            'topic' => 'אירוע',
            'status' => 'סטטוס',
            'response' => 'מה הכתובת ענתה',
        ],
    ],

    'status' => [
        'pending' => 'בשליחה',
        'retrying' => 'לא התקבל — ננסה שוב',
        'succeeded' => 'התקבל',
        'failed' => 'לא התקבל',
    ],

    'notice' => [
        'saved' => 'ה-Webhook נשמר',
        'url_refused' => 'אי אפשר להשתמש בכתובת הזו',
        'url_refused_body' => 'השתמשו בכתובת ציבורית שמתחילה ב-https:// בפורט הרגיל. כתובות מקומיות, פרטיות ופנימיות חסומות.',
        'rotated' => 'המפתח הוחלף',
        'test_sent' => 'הבדיקה נמסרה',
        'test_failed' => 'הבדיקה לא נמסרה',
        'resent' => 'נמסר',
        'resend_failed' => 'לא נמסר',
        'http_status' => 'הכתובת שלכם ענתה HTTP :status.',
        'no_response' => 'אין תשובה: :error',
    ],

    'sample' => [
        'customer_name' => 'דנה כהן',
        'first_name' => 'דנה',
        'last_name' => 'כהן',
        'street' => 'הרצל 1',
        'city' => 'תל אביב',
        'product_title' => 'פולי קפה — מנוי חודשי',
        'decline' => 'הכרטיס נדחה',
    ],
];
