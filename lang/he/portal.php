<?php

/**
 * מחרוזות פורטל הלקוח (שלב 6.5). מוצגות בדף הקסם פונה-ללקוח (לא בפאנל הניהול).
 * משקף כל מפתח מ-lang/en/portal.php. מפתחות הסטטוס תואמים את ערכי ה-enum כדי
 * ש-portal.status_{value} / portal.payment_status_{value} ייפתרו.
 */
return [
    'page_title' => 'ניהול המנוי שלך',
    'heading' => 'המנויים שלך',
    'subtitle' => 'נהל את התוכניות שלך עם :business.',
    'empty' => 'אין לך תוכניות פעילות כרגע.',

    'plan_aria' => 'תוכנית מנוי',
    'kind_installments' => 'תוכנית תשלומים',
    'kind_recurring' => 'מנוי',

    'total' => 'סה״כ',
    'remaining' => 'יתרה לתשלום',
    'per_cycle' => 'לכל מחזור',
    'next_charge' => 'חיוב הבא',
    'next_charge_none' => 'לא מתוזמן',

    // היסטוריה.
    'history_title' => 'היסטוריית תשלומים',
    'history_seq' => '#',
    'history_amount' => 'סכום',
    'history_status' => 'סטטוס',
    'history_date' => 'תאריך',

    // פעולות.
    'action_pause' => 'השהה',
    'action_resume' => 'חדש',
    'action_cancel' => 'בטל תוכנית',
    'confirm_cancel' => 'האם אתה בטוח שברצונך לבטל את התוכנית? לא ניתן לשחזר פעולה זו.',

    'footnote' => 'דף זה פרטי לך. צריך עזרה? צור קשר עם :business.',

    // תוויות סטטוס תוכנית (תואם ערכי PlanStatus).
    'status_draft' => 'טיוטה',
    'status_awaiting_first_payment' => 'ממתין לתשלום ראשון',
    'status_active' => 'פעיל',
    'status_paused' => 'מושהה',
    'status_failed' => 'התשלום נכשל',
    'status_completed' => 'הושלם',
    'status_cancelled' => 'בוטל',

    // תוויות סטטוס תשלום (תואם ערכי PaymentStatus).
    'payment_status_pending' => 'ממתין',
    'payment_status_succeeded' => 'שולם',
    'payment_status_failed' => 'נכשל',
    'payment_status_retry_scheduled' => 'ניסיון חוזר מתוזמן',
    'payment_status_refunded' => 'הוחזר',
];
