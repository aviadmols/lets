<?php

// אנליטיקה › מנויים › סקירה. מראה של lang/en/analytics/subscribers_overview.php.
// מנויים = אנשים, מינויים = תוכניות.
return [
    'as_of_today' => 'פעילים היום',

    'kpi' => [
        'subscribers' => 'מנויים פעילים',
        'subscriptions' => 'מינויים פעילים',
        'quantity' => 'כמות מוצרים במינויים',
        'mrr' => 'MRR פעיל',
    ],

    'line' => [
        'subscriber' => 'מנויים פעילים',
        'subscription' => 'מינויים פעילים',
    ],

    'subscribers_trend' => [
        'title' => 'מגמת מנויים',
        'subtitle' => 'תנועות ומספר המנויים הפעילים',
    ],

    'subscriptions_trend' => [
        'title' => 'מגמת מינויים',
        'subtitle' => 'אותן סדרות ברמת המינוי; ״בוטלו״ במקום ״נטשו״',
    ],

    'subscribers_activity' => ['title' => 'פעילות מנויים'],
    'subscriptions_activity' => ['title' => 'פעילות מינויים'],

    'activity' => [
        'active' => 'מנויים פעילים',
        'active_subscriptions' => 'מינויים פעילים',
        'net' => 'שינוי נטו בפעילים',
        'additions' => 'הצטרפויות',
        'reductions' => 'יציאות',
    ],

    'by_frequency' => [
        'title' => 'לפי תדירות משלוח',
        'caption' => 'פעילים',
    ],

    'by_plan' => [
        'title' => 'לפי תוכנית מנוי',
        'caption' => 'תוכנית מנוי|תוכניות מנוי',
    ],

    'table' => [
        'title' => 'תוכנית מנוי × תדירות',
        'plan' => 'תוכנית מנוי',
        'frequency' => 'תדירות',
        'subscribers' => 'מנויים פעילים',
        'mrr' => 'MRR פעיל',
        'contribution' => 'תרומה',
    ],

    'products' => [
        'title' => 'פעילות מוצרים במינויים',
        'subtitle' => 'יחידות שנוספו והוסרו בכל המינויים',
        'checkout' => 'צ׳ק-אאוט',
        'upsells' => 'שדרוגים',
        'quantity_increased' => 'הגדלת כמות',
        'quantity_decreased' => 'הקטנת כמות',
        'reactivated' => 'הופעלו מחדש',
        'resumed' => 'חודשו',
        'cancelled' => 'בוטלו',
        'paused' => 'הושהו',
        'expired' => 'פגו',
        'net' => 'שינוי כמות נטו · :units יחידות במינויים',
        'note' => 'קו מפריד פירושו ש-LETS עדיין לא מתעדת את התנועה הזו (שדרוג לתוך מינוי, שינוי כמות).',
    ],

    'churn' => [
        'title' => 'סקירת נטישה',
        'subtitle' => 'נטישה ברמת המנוי בתקופה',
        'link' => '← ביטולים',
        'rate' => 'שיעור נטישה',
        'lost' => 'מנויים שאבדו',
        'of_start' => 'מתוך :count בתחילת התקופה',
        'zero_day' => 'נטישה ביום 0',
        'n_of_lost' => ':n מתוך :lost שאבדו',
        'upcoming' => 'נטישה לפני ההזמנה הבאה',
        'cancelled_mrr' => 'MRR שבוטל',
        'cancelled_mrr_by' => 'MRR שבוטל',
        'total' => ':amount MRR בוטל בתקופה',
    ],
];
