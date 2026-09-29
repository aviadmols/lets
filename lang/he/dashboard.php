<?php

// מחרוזות לוח הבית. שיקוף של lang/en/dashboard.php.
return [
    'title' => 'בית',

    'kpi' => [
        'processed_revenue' => 'הכנסות שעובדו',
        'active_subscribers' => 'מנויים פעילים',
        'new_subscribers' => 'מנויים חדשים',
        'churned_subscribers' => 'מנויים שעזבו',
        // beside the delta chip; :days is the selected range
        'compare' => 'לעומת היום הקודם|לעומת :days הימים הקודמים',
    ],

    // the amber strip: the two queues the engine leaves to a human
    'attention' => [
        'charges' => 'חיוב אחד לא נגבה|:count חיובים לא נגבו',
        'invoices' => 'חשבונית אחת דורשת טיפול|:count חשבוניות דורשות טיפול',
        'and' => 'וגם',
        'review_charges' => 'לחיובים שנכשלו',
        'open_invoices' => 'לחשבוניות',
    ],

    'performance' => [
        'title' => 'ביצועים במבט מהיר',
        'period' => 'תקופה',
        'range' => [
            'daily' => 'יומי',
            'weekly' => 'שבועי',
            'monthly' => 'חודשי',
        ],        'this_period' => 'תקופה נוכחית',
        'prev_period' => 'תקופה קודמת',
        'metric_col' => 'מדד',
        'change' => 'שינוי',
        // a rate's move, in percentage points
        'pts' => ':value נק׳',
        'metric' => [
            'mrr' => 'הכנסה חודשית חוזרת',
            'installment_balance' => 'יתרת תשלומים פתוחה',
            'upsell_revenue' => 'הכנסות מהצעות נלוות',
            'charge_success' => 'שיעור חיוב מוצלח',
            'failed_charges' => 'חיובים שנכשלו',
        ],
    ],

    'activity' => [
        'title' => 'פעילות אחרונה',
    ],

    /*
    | מנויים שלא הצלחנו לגבות מהם. הם מוחזקים על התאריך שהם חייבים ולא מקודמים
    | לחודש הבא, ולכן הם לא מחויבים שוב מעצמם עד שמישהו יסדיר או שהלקוח יעדכן כרטיס.
    */
    'unpaid' => [
        'title' => 'לא הצלחנו לחייב',
        'help' => 'ניסינו פעם ביום במשך שבוע והתשלום לא עבר. המנויים האלה בהשהיה — הם לא יחויבו שוב מעצמם. פתחו מנוי כדי לחייב אותו, או שלחו ללקוח קישור לעדכון כרטיס.',
        'customer' => 'לקוח',
        'amount' => 'סכום',
        'due' => 'מחזור שחייב',
        'since' => 'בהשהיה',
    ],

    'upcoming' => [
        'title' => 'הזמנות קרובות',
        'customer' => 'לקוח',
        'type' => 'סוג',
        'amount' => 'סכום',
        'date' => 'חיוב הבא',
        'empty' => 'אין חיובים קרובים מתוזמנים.',
        'view_all' => 'לכל המנויים ←',
    ],

    'empty' => [
        'first_run' => [
            'title' => 'בואו נעבד את התשלום הראשון',
            'body' => 'חברו את חשבון PayPlus וצרו תוכנית כדי לראות כאן נתונים חיים.',
            'cta' => 'חיבור PayPlus',
        ],
    ],
];
