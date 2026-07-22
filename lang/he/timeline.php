<?php

// תוויות סוגי אירועים בציר הזמן (EventPresenter). שיקוף של lang/en/timeline.php.
return [
    'kind' => [
        'plan_created' => 'התוכנית נוצרה',
        'charge_succeeded' => 'החיוב הצליח',
        'charge_failed' => 'החיוב נכשל',
        'retry_scheduled' => 'תוזמן ניסיון חוזר',
        'refund_succeeded' => 'בוצע זיכוי',
        'state_changed' => 'הסטטוס השתנה',
        'plan_edited' => 'המנוי נערך',
        'plan_completed' => 'התוכנית הושלמה',
        'plan_cancelled' => 'התוכנית בוטלה',
        'plan_paused' => 'התוכנית הושהתה',
        'fulfillment_released' => 'ההזמנה שוחררה למימוש',
        'email_sent' => 'נשלח אימייל',
        'webhook_received' => 'התקבל Webhook',
        // הפקת חשבוניות (חשבונית ירוקה). התווית היא כל מה שמוצג — לעולם לא הקישור.
        'document_requested' => 'התבקשה חשבונית',
        'document_issued' => 'הופקה חשבונית',
        'document_failed' => 'הפקת החשבונית נכשלה',
        'document_retried' => 'הסוחר ניסה להפיק את החשבונית מחדש',
        'document_force_issued' => 'החשבונית הופקה לאחר שהסוחר בדק בחשבונית ירוקה',
        'generic' => 'פעילות',
    ],

    // תוויות שדות לסיכום "המנוי נערך" (ישן ← חדש).
    'field' => [
        'next_charge_at' => 'חיוב הבא',
        'amount' => 'סכום',
        'items' => 'מוצרים',
    ],
];
