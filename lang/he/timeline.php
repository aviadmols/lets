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
        'customer_details_updated' => 'פרטי ההתקשרות עודכנו',
        'admin_note' => 'הערה',
        'customer_impersonated' => 'כניסה לחנות בתור הלקוח',
        'customer_viewed_as' => 'צפייה באזור האישי של הלקוח (קריאה בלבד)',
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
        'document_restamped' => 'הסוחר שלח את החשבונית מחדש להזמנה בחנות',
        'document_force_issued' => 'החשבונית הופקה לאחר שהסוחר בדק בחשבונית ירוקה',
        'price_stepped_up' => 'הנחת הפתיחה הסתיימה — המחיר עלה למחיר הרגיל',
        'checkout_discount_captured' => 'נקלט קופון מהזמנת הרכישה',
        // הצעות באזור האישי. מעבר מסלול הוא לא ביטול — דוח נטישה שיקרא אותו
        // ככזה הוא בדיוק הסיבה שיש לאירועים האלה סוג משלהם.
        'account_offer_accepted' => 'הצעה התקבלה באזור האישי',
        'plan_switched' => 'המנוי הוחלף',
        'account_offer_charge_failed' => 'ההצעה התקבלה, אך הכרטיס נדחה',
        'account_action_failed' => 'פעולה באזור האישי נדחתה',
        'store_order_failed' => 'החיוב הצליח, אך יצירת ההזמנה בחנות נכשלה',
        'shopify_subscription_resumed' => 'המנוי חודש',
        'shopify_subscription_rescheduled' => 'תאריך החיוב הבא שונה',
        'shopify_subscription_bill_now' => 'נשלחה בקשת חיוב מיידי ל-Shopify',
        'shopify_subscription_products_edited' => 'מוצרי המנוי עודכנו',
        'shopify_subscription_card_update_email' => 'נשלח ללקוח מייל לעדכון כרטיס',
        'card_updated' => 'הלקוח עדכן את הכרטיס',
        'card_update_started' => 'הלקוח פתח את דף עדכון הכרטיס',
        'account_action' => 'הלקוח ביצע פעולה באזור האישי',
        'customer_address_updated' => 'הלקוח עדכן את הכתובת שלו בחנות',
        'campaign_email_sent' => 'נשלח מייל קמפיין',
        'campaign_login_used' => 'כניסה לאזור האישי מקישור במייל קמפיין',
        'campaign_unsubscribed' => 'הסרה מרשימת הדיוור',
        'generic' => 'פעילות',
    ],

    // תוויות שדות לסיכום "המנוי נערך" (ישן ← חדש).
    'field' => [
        'next_charge_at' => 'חיוב הבא',
        'amount' => 'סכום',
        'items' => 'מוצרים',
    ],

    // הפעולה שהלקוח לחץ עליה (סיכום account_action_failed).
    'action' => [
        'pause' => 'השהיית מנוי',
        'resume' => 'חידוש מנוי',
        'cancel' => 'ביטול מנוי',
        'skip' => 'דילוג על המשלוח הבא',
        'reschedule' => 'שינוי תאריך החיוב הבא',
        'items' => 'עריכת ההזמנה הבאה',
        'accept_offer' => 'קבלת הצעה',
    ],

    // הסיבה שהלחיצה נדחתה (סיכום account_action_failed).
    'result' => [
        'not_allowed' => 'הפעולה אינה מותרת למנוי הזה',
        'bad_state' => 'המנוי אינו במצב שמאפשר את הפעולה',
        'invalid' => 'המנוי או ההצעה לא נמצאו',
        'unavailable' => 'ההצעה אינה זמינה כרגע',
        'not_eligible' => 'המנוי אינו זכאי להצעה',
        'changed' => 'ההצעה השתנתה בזמן שהעמוד היה פתוח',
    ],
];
