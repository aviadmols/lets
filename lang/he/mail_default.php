<?php

/*
 * The PLATFORM'S default email copy — what a shop that has never edited a
 * template sends.
 *
 * It lives here, and not inside DefaultEmailTemplates, so the same words serve
 * both the send and the preview and can exist in more than one language. The
 * merchant picks the language their CUSTOMERS read in Settings → Email; the
 * mail path binds that locale around rendering, exactly as the loyalty page
 * binds its own.
 *
 * Mirror every key in lang/en/mail_default.php. A missing key here reaches a
 * customer as a raw dotted string.
 */

return [

    'subject' => [
        'first_payment_welcome' => 'ברוכים הבאים — התשלום הראשון התקבל ({business_name})',
        'recurring_payment_reminder' => 'תזכורת: חיוב קרוב בתאריך {next_charge_date} ({business_name})',
        'manual_recurring_payment' => 'בקשת תשלום — {business_name}',
        'charge_succeeded' => 'התשלום בסך {amount} {currency} התקבל ({business_name})',
        'charge_failed' => 'החיוב נכשל — נדרשת פעולה ({business_name})',
        'plan_cancelled' => 'התוכנית בוטלה — {business_name}',
        'login_code' => 'קוד הכניסה שלך: {code}',
        'order_updated' => 'ההזמנה שלך עודכנה — {business_name}',
    ],

    'greeting' => 'שלום {customer_name},',
    'footer' => 'מספר תוכנית #{plan_id} · {business_name}',
    'amount' => 'סכום: {amount} {currency}',

    /* The two ready-made sentences. They are VARS and not template conditionals
       because an open-ended subscription must show neither — see
       TemplateRenderer::progress(). */
    'progress' => ' (תשלום %s מתוך %s)',
    'total_note' => 'סך הכל %s תשלומים בתוכנית. ',

    'first_payment_welcome' => [
        'lead' => 'תודה! התשלום הראשון עבור <strong>{product_title}</strong> התקבל בהצלחה.',
        'next' => '{installment_total_note}החיוב הבא צפוי בתאריך {next_charge_date}.',
        'cta' => 'צפייה בתוכנית שלי',
    ],

    'recurring_payment_reminder' => [
        'lead' => 'זוהי תזכורת שהחיוב הבא עבור <strong>{product_title}</strong> צפוי בתאריך <strong>{next_charge_date}</strong>.',
        'cta' => 'ניהול המנוי שלי',
    ],

    'manual_recurring_payment' => [
        'lead' => 'הגיע מועד התשלום עבור <strong>{product_title}</strong>. נא להשלים את התשלום עד {due_date}.',
        'amount' => 'סכום לתשלום: {amount} {currency}',
        'cta' => 'תשלום עכשיו',
    ],

    'charge_succeeded' => [
        'lead' => 'התשלום עבור <strong>{product_title}</strong> התקבל בהצלחה{installment_progress}.',
        'cta' => 'צפייה בחשבונית',
        'secondary' => 'ניהול התוכנית שלי',
    ],

    'charge_failed' => [
        'lead' => 'לא הצלחנו לחייב את אמצעי התשלום עבור <strong>{product_title}</strong>.',
        'reason' => 'סיבה: {failure_reason}. ננסה שוב בתאריך {next_retry_date}. ניתן לעדכן את אמצעי התשלום מראש:',
        'cta' => 'עדכון אמצעי תשלום',
    ],

    'plan_cancelled' => [
        'lead' => 'התוכנית עבור <strong>{product_title}</strong> בוטלה. {cancellation_reason}',
        'cta' => 'צפייה בהיסטוריה',
    ],

    'login_code' => [
        'heading' => 'קוד הכניסה שלך',
        'lead' => 'הזינו את הקוד הבא באזור האישי של {business_name}:',
        'valid' => 'הקוד תקף ל-{expires_minutes} דקות.',
        'footer' => 'לא ביקשתם קוד? אפשר להתעלם מההודעה — בלי הקוד אי אפשר להיכנס. · {business_name}',
    ],

    /* טיוטת הפתיחה של קמפיין מייל חדש — לא ברירת מחדל אלא נקודת התחלה שהסוחר
       עורך. מגיעה תקנית: כפתור לכניסה לאזור האישי וקישור הסרה בתחתית. */
    'campaign' => [
        'subject' => 'עדכון קטן מ{business_name}',
        'lead' => 'יש לנו משהו חדש בשבילכם. כל מה שקשור למנוי — המשלוח הבא, אמצעי התשלום והפרטים שלכם — נמצא במרחק לחיצה אחת.',
        'cta' => 'כניסה לאזור האישי',
        'signature' => '{business_name}',
        'unsubscribe' => 'הסרה מרשימת הדיוור',
    ],

    'order_updated' => [
        'heading' => 'ההזמנה שלך עודכנה',
        'lead' => 'הוספתם פריטים להזמנה <strong>#{order_number}</strong> אחרי התשלום. הנה הפרטים המעודכנים:',
        'total' => 'סה"כ שנוסף: {added_total} {currency}',
        'closing' => 'ההזמנה יוצאת לדרך עכשיו. תודה!',
        'footer' => 'הזמנה #{order_number} · {business_name}',
    ],

];
