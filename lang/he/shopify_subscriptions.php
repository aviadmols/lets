<?php

// תשלומים ← מנויי Shopify (מסלול הפיילוט של Shopify Payments). הסטטוסים משקפים
// את אוצר המילים של SHOPIFY — שני המסלולים לעולם לא חולקים enum.
// מראה של lang/en/shopify_subscriptions.php — כל מפתח חייב להתקיים בשניהם.
return [

    'empty' => 'אין עדיין מנויי Shopify. הם יופיעו כאן כשלקוח יירשם למנוי בקופה.',
    'empty_needs_scopes' => 'אם לקוח כבר נרשם למנוי, המנוי קיים ב-Shopify אבל האפליקציה עדיין לא רשאית לקרוא אותו: Shopify חוסמת חוזי מנויים מאחורי בקשת גישה מאושרת (read_own_subscription_contracts, write_own_subscription_contracts, read_customer_payment_methods). יש לבקש אותה בפרטנר דשבורד תחת API access; המנויים יופיעו כאן — ויתחילו להתחייב — מרגע שהיא תאושר.',

    'status' => [
        'ACTIVE' => 'פעיל',
        'PAUSED' => 'מושהה',
        'CANCELLED' => 'בוטל',
        'EXPIRED' => 'הסתיים',
        'FAILED' => 'בעיית תשלום',
    ],

    'col' => [
        'attempts' => 'ניסיונות חיוב',
        'synced' => 'סונכרן',
        'stale' => 'דורש סנכרון',
    ],

    'action' => [
        'menu' => 'פעולות',
        'charge_now' => 'חיוב התשלום הבא עכשיו',
        'charge_now_body' => 'Shopify מתבקשת לחייב את התשלום הבא באופן מיידי, בכרטיס השמור. התוצאה (שולם / נכשל) מגיעה מ-Shopify תוך דקות ותופיע תחת "ניסיונות חיוב".',
        'charge_now_requested' => 'בקשת החיוב נשלחה — Shopify מעבדת אותה. התוצאה תופיע תחת "ניסיונות חיוב" בקרוב.',
        'pause' => 'השהיה',
        'resume' => 'חידוש',
        'cancel' => 'ביטול',
        'cancel_body' => 'המנוי מבוטל ב-Shopify והלקוח לא יחויב שוב. לא ניתן לבטל את הפעולה מכאן — מנוי חדש דורש רכישה חדשה בקופה.',
        'skip' => 'דילוג על החיוב הבא',
        'skip_body' => 'החיוב הבא נדחה במחזור שלם אחד. המנוי נשאר פעיל ולא מתבצע חיוב בינתיים.',
        'reschedule' => 'שינוי תאריך החיוב הבא',
        'reschedule_date' => 'תאריך החיוב הבא',
        'sync' => 'סנכרון מ-Shopify',
        'synced' => 'נקרא מחדש מ-Shopify.',
        'done' => 'בוצע — Shopify החילה את השינוי.',
        'failed' => 'Shopify לא החילה את השינוי',
    ],

    'detail' => [
        'title' => 'מנוי',
        'untitled' => 'מנוי',
        'customer_ref' => 'לקוח #:id',
        'customer_pending_approval' => 'שם ואימייל של הלקוח הם "נתוני לקוח מוגנים" — Shopify חושפת אותם לאפליקציה רק לאחר אישור בקשת Protected Customer Data (פרטנר דשבורד ← API access). בינתיים הקישור פותח את הלקוח במנהל של Shopify.',
        'subheading' => ':amount :cadence',
        'shopify_owns' => 'המנוי הזה מנוהל ב-Shopify; הדף הזה משקף אותו. כל שינוי כאן נשלח ל-Shopify ונרשם רק אחרי ש-Shopify מאשרת אותו.',
        'cadence' => 'מחזור חיוב',
        'items' => 'פריטים',
        'items_empty' => 'עדיין לא נרשמו פריטים. סנכרנו מ-Shopify כדי למשוך אותם.',
        'item' => 'פריט',
        'qty' => 'כמות',
        'attempts' => 'ניסיונות חיוב',
        'attempts_empty' => 'עדיין לא היה ניסיון חיוב. הראשון יתבצע בתאריך החיוב הבא.',
        'cycle' => 'מחזור',
        'requested' => 'נשלח',
        'outcome' => 'תוצאה',
        'overview' => 'סקירה',
        'customer' => 'פרטי הלקוח',
        'created_on' => 'נוצר בתאריך',
        'paid_cycles' => 'הזמנות שהושלמו',
        'per_cycle_total' => 'סה"כ למחזור',
        'tab_schedule' => 'לוח הזמנות',
        'tab_history' => 'היסטוריית הזמנות',
        'scheduled' => 'מתוזמן',
        'charge_now_row' => 'חיוב עכשיו',
        'schedule_empty' => 'אין חיוב קרוב — למנוי אין תאריך חיוב הבא.',
        'schedule_projection_note' => 'התאריכים חושבו לפי מחזור החיוב; Shopify היא בעלת לוח הזמנות. רק את החיוב הבא ניתן להזיז או לחייב מוקדם.',
        'activity' => 'פעילות',
    ],

    // עריכת מוצרי המנוי (טיוטה ← אישור ב-Shopify).
    'lines' => [
        'add' => 'הוספת מוצר',
        'edit' => 'עריכת מוצר',
        'remove' => 'הסרת מוצר',
        'remove_body' => 'המוצר מוסר מהמנוי הזה ב-Shopify. חיובים עתידיים לא יכללו אותו.',
        'product' => 'מוצר',
        'unit_price' => 'מחיר ליחידה',
    ],

    'payment' => [
        'title' => 'פרטי תשלום',
        'expires' => 'בתוקף עד',
        'none' => 'אין אמצעי תשלום רשום.',
        'card_pending_approval' => 'הכרטיס שמור בכספת של Shopify; המותג והספרות האחרונות ייחשפו לאחר אישור ה-Protected Customer Data.',
        'send_update_email' => 'שליחת מייל לעדכון כרטיס',
        'send_update_email_body' => 'Shopify שולחת ללקוח מייל עם דף מאובטח לעדכון הכרטיס. הכרטיס עצמו לעולם לא עובר דרך האפליקציה.',
        'update_email_sent' => 'Shopify שולחת ללקוח את דף עדכון הכרטיס במייל.',
    ],

    'attempt' => [
        'requested' => 'נשלח',
        'succeeded' => 'שולם',
        'failed' => 'נכשל',
        'challenged' => 'דורש פעולה מהלקוח',
        'pending' => 'ממתין ל-Shopify',
    ],

    // "כל חודש" / "כל שבועיים" — המחזור בשפה של הסוחר.
    'cadence' => [
        'every' => 'כל :unit',
        'every_n' => 'כל :count :unit',
    ],

    'interval' => [
        'DAY' => ['one' => 'יום', 'many' => 'ימים'],
        'WEEK' => ['one' => 'שבוע', 'many' => 'שבועות'],
        'MONTH' => ['one' => 'חודש', 'many' => 'חודשים'],
        'YEAR' => ['one' => 'שנה', 'many' => 'שנים'],
    ],

    'reason' => [
        'shopify_rejected' => 'Shopify דחתה את הבקשה. ייתכן שהחוזה השתנה — רעננו ונסו שוב.',
        'transport' => 'לא ניתן להגיע ל-Shopify. נסו שוב בעוד רגע.',
        'not_found' => 'Shopify כבר לא מזהה את החוזה הזה.',
        'bad_date' => 'בחרו תאריך עתידי.',
        'not_billable' => 'ניתן לחייב רק מנוי פעיל.',
        'already_requested' => 'כבר קיים ניסיון חיוב למחזור הזה — בדקו תחת "ניסיונות חיוב".',
    ],
];
