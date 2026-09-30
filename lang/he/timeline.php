<?php

// תוויות סוגי אירועים בציר הזמן (EventPresenter). שיקוף של lang/en/timeline.php.
return [
    'kind' => [
        'plan_created' => 'התוכנית נוצרה',
        'plan_created_manually' => 'נוסף ידנית — בלי אמצעי תשלום',
        'charge_refused_no_charge_plan' => 'לא חויב — המנוי הזה ללא תשלום',
        'charge_refused_zero_amount' => 'לא חויב — אין סכום לגבות במחזור הזה',
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
        'store_order_skipped' => 'חיוב בוצע — ללא הזמנה בחנות, לפי הגדרות החנות',
        'shopify_subscription_resumed' => 'המנוי חודש',
        'shopify_subscription_rescheduled' => 'תאריך החיוב הבא שונה',
        'shopify_subscription_bill_now' => 'נשלחה בקשת חיוב מיידי ל-Shopify',
        'shopify_subscription_cycle_advanced' => 'המחזור שולם — תאריך החיוב הבא התקדם',
        'shopify_subscription_products_edited' => 'מוצרי המנוי עודכנו',
        'shopify_subscription_card_update_email' => 'נשלח ללקוח מייל לעדכון כרטיס',
        'card_updated' => 'הלקוח עדכן את הכרטיס',
        'subscription_awaiting_activation' => 'שולם — ממתין שהלקוח יפעיל אותו',
        'subscription_activated' => 'המנוי הופעל',
        'activation_link_sent' => 'קישור ההפעלה נשלח במייל',
        'activation_link_revoked' => 'קישורי ההפעלה בוטלו',
        'payment_method_token_recovered' => 'נמצא כרטיס שמור ב-PayPlus ושויך למנוי',
        'payment_method_token_needs_confirmation' => 'נמצא כרטיס שמור ב-PayPlus — לא שויך עד שתאשרו שהוא של הלקוח',
        'card_update_started' => 'הלקוח פתח את דף עדכון הכרטיס',
        'card_update_link_sent' => 'נוצר קישור לעדכון כרטיס',
        'charge_repeat_blocked' => 'חיוב נעצר — המנוי כבר חויב ביממה האחרונה',
        'invoicing_report_refused' => 'לא הופק מסמך להזמנה מהחנות — הדיווח לא תאם את החנות המחוברת או את הסכום ש-LETS רשם',
        'charge_above_consent' => 'חיוב נעצר — מעל הסכום או התדירות שהלקוח הסכים להם',
        'consent_override_approved' => 'חיוב מעל הסכמת הלקוח אושר על ידי בית העסק',
        'charge_repeat_approved' => 'חיוב נוסף באותה יממה — באישור מפורש של מנהל',
        'cycles_forgiven' => 'מחזורים שעברו לא נגבו — לפי הגדרות החנות; החיוב הבא נשאר ביום הקבוע',
        'card_update_failed' => 'הלקוח ניסה לעדכן כרטיס — הכרטיס נדחה',
        'card_update_not_saved' => 'הלקוח השלים את דף PayPlus, אבל הכרטיס לא נשמר על המנוי',
        'account_action' => 'הלקוח ביצע פעולה באזור האישי',
        'customer_address_updated' => 'הלקוח עדכן את הכתובת שלו בחנות',
        'campaign_email_sent' => 'נשלח מייל קמפיין',
        'campaign_login_used' => 'כניסה לאזור האישי מקישור במייל קמפיין',
        'campaign_unsubscribed' => 'הסרה מרשימת הדיוור',
        // שלבי החיוב עצמם.
        'charge_attempt_started' => 'התחיל ניסיון חיוב',
        'charge_retry_scheduled' => 'החיוב נכשל — תוזמן ניסיון חוזר',
        'charge_in_flight' => 'לא הופעל — ניסיון אחר לאותו תשלום כבר בתהליך',
        'charge_needs_reconcile' => 'תוצאת החיוב לא ידועה — יש לבדוק ב-PayPlus לפני חיוב נוסף',
        'charge_reconciled' => 'חיוב שתוצאתו לא הייתה ידועה טופל לאחר בדיקה ב-PayPlus',
        'charging_paused' => 'לא חויב — החיוב בפועל כבוי בחנות',
        'charging_resumed_rolled_forward' => 'החיוב בפועל הופעל מחדש — מועד החיוב שהוחמץ הוזז קדימה',
        'consent_missing' => 'לא חויב — אין הסכמה שמורה של הלקוח לחיובים עתידיים',
        'manual_payment_pending' => 'לא חויב — בקשת התשלום הקודמת עדיין לא שולמה',
        'payment_status_changed' => 'סטטוס התשלום השתנה',
        // זיכויים וביטולים.
        'refund_requested' => 'התבקש זיכוי',
        'store_refund_synced' => 'ההזמנה בחנות עודכנה בזיכוי',
        'store_refund_sync_failed' => 'הזיכוי בוצע, אך עדכון ההזמנה בחנות נכשל',
        'restocked' => 'המלאי הוחזר לחנות',
        'order_cancelled_by_merchant' => 'ההזמנה בוטלה על ידי בית העסק',
        // איך התוכנית נוצרה.
        'deposit_plan_created' => 'נוצרה תוכנית מקדמה ותשלומים',
        'deposit_paid_plan_activated' => 'המקדמה שולמה — התוכנית הופעלה',
        'recurring_plan_created' => 'המנוי נוצר',
        'subscription_imported' => 'יובא מקובץ',
        'subscription_import_updated' => 'עודכן מקובץ ייבוא',
        'subscription_import_released' => 'מנוי מיובא שוחרר לחיוב',
        // הצעות לאחר רכישה.
        'upsell_charge_succeeded' => 'הצעה לאחר רכישה חויבה',
        'upsell_charge_failed' => 'הצעה לאחר רכישה — הכרטיס נדחה',
        'upsell_child_order_failed' => 'הצעה לאחר רכישה חויבה, אך יצירת ההזמנה בחנות נכשלה',
        'upsell_no_payment_method' => 'הצעה לאחר רכישה לא חויבה — אין כרטיס שמור',
        // בקשות פרטיות.
        'customer_redacted' => 'נתוני הלקוח נמחקו (בקשת פרטיות)',
        'shop_redacted' => 'נתוני החנות נמחקו (בקשת פרטיות)',
        'customer_data_exported' => 'נתוני הלקוח יוצאו (בקשת פרטיות)',
        'generic' => 'פעילות',
    ],

    // תוויות שדות לסיכום "המנוי נערך" (ישן ← חדש).
    'field' => [
        'next_charge_at' => 'חיוב הבא',
        'amount' => 'סכום',
        'items' => 'מוצרים',
        'billing_frequency' => 'תדירות חיוב',
    ],

    // השינוי הזה היה חלק מעריכה קבוצתית — מוצג בציר הזמן של המנוי עצמו.
    'bulk_edit' => 'כחלק מעריכה קבוצתית #:id',

    // הפעולה שהלקוח לחץ עליה (סיכום account_action_failed).
    'action' => [
        'pause' => 'השהיית מנוי',
        'resume' => 'חידוש מנוי',
        'cancel' => 'ביטול מנוי',
        'skip' => 'דילוג על המשלוח הבא',
        'reschedule' => 'שינוי תאריך החיוב הבא',
        'items' => 'עריכת ההזמנה הבאה',
        'accept_offer' => 'קבלת הצעה',
        'update_card' => 'עדכון כרטיס',
        // למה הסטטוס זז (הקשר של status_changed).
        'first_payment_succeeded' => 'התשלום הראשון עבר',
        'charge_attempted' => 'בוצע ניסיון חיוב',
        'charge_failed' => 'חיוב נכשל',
        'dunning_resumed' => 'ניסיונות החיוב חודשו',
        'unpaid_cycle_held' => 'נגמרו ניסיונות החיוב — מושהה עד שהמחזור ישולם',
        'held_for_unpaid_cycle' => 'נגמרו ניסיונות החיוב — המנוי מושהה עד שהמחזור הזה ישולם',
        'unpaid_cycle_settled' => 'המחזור שלא שולם נפרע',
        'payment_recovered' => 'התקבל תשלום',
        'activated' => 'הלקוח הפעיל את המנוי',
        'paid_awaiting_activation' => 'שולם — ממתין שהלקוח יפעיל',
        'card_replaced' => 'הכרטיס הוחלף',
        'switch_scheduled' => 'מעבר מתוזמן נכנס לתוקף',
        'paused' => 'הושהה',
        'resumed' => 'חודש',
        'cancelled' => 'בוטל',
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
    // בין שני צדי שינוי ("ישן ← חדש").
    'arrow' => '←',

    // איזה חיוב של המנוי.
    'charge_n' => 'חיוב מס׳ :n',

    // מתי נשלחה התזכורת.
    'offset_hours' => 'נשלח :n שעות לפני החיוב',

    // תוויות "תווית: ערך" לפרטים שמתחת לכותרת השורה (TimelineSummary).
    'label' => [
        'email' => 'אימייל',
        'offer' => 'הצעה',
        'product' => 'מוצר',
        'status' => 'סטטוס',
        'cycles_skipped' => 'מחזורים שלא נגבו',
        'period' => 'תקופה',
        'consented_amount' => 'הסכום שהלקוח הסכים לו',
        'next_charge' => 'חיוב הבא',
        'first_charge' => 'חיוב ראשון',
        'still_due' => 'המחזור שלא שולם',
        'updated_fields' => 'עודכן',
        'address' => 'כתובת',
        'charge_type' => 'סוג חיוב',
        'added_lines' => 'מוצרים שנוספו',
        'document_type' => 'סוג מסמך',
        'document_number' => 'מספר מסמך',
        'for' => 'עבור',
        'consent_for' => 'נדרשת הסכמה עבור',
        'card' => 'כרטיס',
        'approval_number' => 'מספר אישור',
        'attempt' => 'ניסיון',
        'reason' => 'סיבה',
        'next_retry' => 'ניסיון הבא',
        'last_charge' => 'חויב לאחרונה',
        'sent_to_gateway' => 'נשלח ל-PayPlus',
        'resolved_as' => 'התברר כ',
        'reported_total' => 'החנות דיווחה',
        'recorded_total' => 'LETS רשם',
        'price' => 'מחיר',
        'coupon' => 'קופון',
        'deposit' => 'מקדמה',
        'total' => 'סך הכול',
        'installments' => 'מספר תשלומים',
        'frequency' => 'תדירות',
        'order' => 'הזמנה',
        'refund_request' => 'בקשת זיכוי',
        'store_reference' => 'אסמכתה בחנות',
        'paid_cycle' => 'מחזור ששולם',
        'sent_to' => 'נשלח אל',
        'channel' => 'באמצעות',
        'plans_updated' => 'מנויים שעודכנו',
        'file' => 'קובץ',
        'line' => 'שורה',
        'membership_id' => 'מספר חבר',
        'switch' => 'מנוי',
        'records' => 'רשומות',
        'campaign' => 'קמפיין',
    ],

    // למה המערכת פעלה (הסיבה נאמרת ראשונה בשורה).
    'trigger' => [
        'merchant' => 'חיוב יזום מהניהול',
        'retry' => 'ניסיון חוזר אוטומטי',
        'auto_renewal' => 'חידוש אוטומטי',
        'schedule' => 'תשלום לפי לוח התשלומים',
        'store_order_paid' => 'לפי תשלום ההזמנה בחנות',
        'payplus_callback' => 'לפי אישור מ-PayPlus',
        'store_webhook' => 'לפי עדכון מהחנות',
        'store_report' => 'לפי דיווח מהחנות',
        'privacy_request' => 'לפי בקשת פרטיות',
    ],

    // מאיפה הגיעו התוכנית או הזיכוי.
    'source' => [
        'admin_manual' => 'נוסף ידנית בניהול',
        'csv_import' => 'יובא מקובץ CSV',
        'import_release' => 'שוחרר מייבוא',
        'wc_cart_gateway' => 'שולם בקופת החנות',
        'account_offer' => 'מהצעה באזור האישי',
        'store' => 'זוכה בחנות',
    ],

    // איזה אימייל נשלח.
    'email_template' => [
        'first_payment_welcome' => 'ברוכים הבאים אחרי התשלום הראשון',
        'recurring_payment_reminder' => 'תזכורת לפני חיוב',
        'manual_recurring_payment' => 'בקשת תשלום',
        'charge_succeeded' => 'אישור תשלום',
        'charge_failed' => 'התשלום נכשל',
        'plan_cancelled' => 'אישור ביטול',
    ],

    // למה חיוב או בדיקה סירבו, במילים פשוטות.
    'reason' => [
        'live_charging_resumed' => 'החיוב בפועל הופעל מחדש',
        'amount_above_consent' => 'הסכום גבוה ממה שהלקוח הסכים לו',
        'cadence_tighter_than_consent' => 'החיוב תכוף יותר ממה שהלקוח הסכים לו',
        'stolen_or_lost_decline' => 'הכרטיס הקודם נדחה כגנוב או אבוד',
        'not_proven_same_card' => 'לא הוכח שזה אותו כרטיס',
        'order_not_created' => 'ההזמנה בחנות לא נוצרה',
        'no_token' => 'PayPlus לא החזיר כרטיס שמור',
        'link_revoked' => 'הקישור בוטל קודם לכן',
        'site_required' => 'הדיווח לא ציין מאיזו חנות נשלח',
        'site_mismatch' => 'הדיווח הגיע מחנות אחרת',
        'total_exceeds_recorded' => 'הסכום גבוה ממה ש-LETS רשם',
    ],

    // סטטוסים של מבצע לאחר רכישה / מסלול מוצר.
    'flow_status' => [
        'draft' => 'טיוטה',
        'active' => 'פעיל',
        'inactive' => 'לא פעיל',
    ],

    // עבור מה היה החיוב או המסמך.
    'context' => [
        'deposit' => 'מקדמה',
        'installment' => 'תשלום',
        'final_installment' => 'תשלום אחרון',
        'recurring' => 'חידוש מנוי',
        'recurring_cycle' => 'חידוש מנוי',
        'upsell' => 'הצעה לאחר רכישה',
        'platform_order' => 'הזמנה בחנות',
        'refund' => 'זיכוי',
        'cancellation' => 'ביטול',
        'retry' => 'ניסיון חוזר',
        'manual' => 'תשלום ידני',
        'gateway' => 'קופת החנות (PayPlus)',
        'account_offer' => 'הצעה מהאזור האישי',
    ],

    'consent_context' => [
        'installments' => 'תשלומים',
        'recurring' => 'מנוי מתחדש',
        'upsell' => 'הצעות לאחר רכישה',
    ],

    // סוגי מסמכים חשבונאיים (קודי חשבונית ירוקה + שמות המדיניות).
    'document_type' => [
        '300' => 'חשבונית עסקה',
        '305' => 'חשבונית מס',
        '320' => 'חשבונית מס / קבלה',
        '330' => 'חשבונית זיכוי',
        '400' => 'קבלה',
        '405' => 'קבלת תרומה',
        'invoice_receipt' => 'חשבונית מס / קבלה',
        'tax_invoice_receipt' => 'חשבונית מס / קבלה',
        'tax_invoice' => 'חשבונית מס',
        'receipt' => 'קבלה',
        'credit_invoice' => 'חשבונית זיכוי',
    ],

    'refund_mode' => [
        'cancel_order' => 'ביטול ההזמנה כולה',
        'refund_full' => 'זיכוי מלא',
        'refund_partial' => 'זיכוי חלקי',
    ],

    'offer_mode' => [
        'add' => 'נוסף לצד המנוי הקיים',
        'replace' => 'מחליף את המנוי הקיים',
    ],

    'address_type' => [
        'billing' => 'לחיוב',
        'shipping' => 'למשלוח',
    ],

    'channel' => [
        'email' => 'אימייל',
        'sms' => 'SMS',
        'copy' => 'קישור שהועתק',
    ],

    'contact_field' => [
        'name' => 'שם',
        'email' => 'אימייל',
        'phone' => 'טלפון',
        'national_id' => 'תעודת זהות',
        'address' => 'כתובת',
    ],

    'frequency' => [
        'daily' => 'יומי',
        'weekly' => 'שבועי',
        'biweekly' => 'כל שבועיים',
        'monthly' => 'חודשי',
        'quarterly' => 'רבעוני',
        'yearly' => 'שנתי',
    ],

    'every' => [
        'daily' => 'כל :n ימים',
        'weekly' => 'כל :n שבועות',
        'biweekly' => 'כל :n תקופות של שבועיים',
        'monthly' => 'כל :n חודשים',
        'quarterly' => 'כל :n רבעונים',
        'yearly' => 'כל :n שנים',
    ],

    // עובדות כן/לא, נאמרות רק כשהן נכונות.
    'flag' => [
        'token_captured' => 'הכרטיס נשמר לחיובים הבאים',
        'token_not_captured' => 'לא נשמר כרטיס לחיובים הבאים',
        'is_final' => 'תשלום אחרון',
        'restock' => 'המלאי הוחזר',
        'needs_reconcile' => 'דורש בדיקה ידנית',
        'verified_absent_by_merchant' => 'בית העסק אישר שלא קיים מסמך',
        'reconciled_by_merchant' => 'שויך למסמך קיים על ידי בית העסק',
        'skipped_delivery' => 'המשלוח דולג',
        'will_retry' => 'יבוצע ניסיון נוסף',
        'repaired' => 'הושלם בסבב מאוחר יותר',
    ],
];
