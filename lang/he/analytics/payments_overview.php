<?php

// Analytics › Payments › Overview — Hebrew. Mirrors lang/en/analytics/payments_overview.php key for key.
return [
    'unit' => [
        'label' => 'כמות או הכנסות',
        'count' => 'כמות',
        'revenue' => 'הכנסות',
    ],

    'kpi' => [
        'attempted' => 'סך ניסיונות החיוב',
        'success' => 'הצלחה',
        'recovered' => 'שוחזרו',
        'under' => 'בתהליך שחזור',
        'lost' => 'אבדו',
    ],

    'monthly' => [
        'title' => 'הכנסות שהצליחו מול שנכשלו',
        'subtitle' => '12 החודשים האחרונים · אחוז ההצלחה מעל כל חודש',
        'realized' => 'נגבו',
        'under' => 'בתהליך שחזור',
        'lost' => 'אבדו',
        'success' => 'אחוז הצלחה',
    ],

    'no_journeys' => 'לא נרשמו ניסיונות חיוב בציר הזמן בתקופה הזו.',

    'first_attempt' => [
        'title' => 'מדדי ניסיון ראשון',
        'subtitle' => 'חיובים לפי הניסיון הראשון שלהם, בתקופה שנבחרה',
        'rate' => 'אחוז הצלחה',
        'attempted' => 'ניסיונות',
        'realized' => 'נגבו',
    ],

    'backup' => [
        'title' => 'מדדי אמצעי תשלום חלופי',
        'subtitle' => 'כרטיס גיבוי שמחויב לפני מחזור השחזור',
        'not_tracked' => 'LETS מחייבת כרטיס שמור אחד לכל מינוי — אין כרטיס גיבוי לנסות, ולכן אין מה למדוד.',
    ],

    'cycle' => [
        'first_cycle' => 'מחזור שחזור ראשון',
        'first_cycle_sub' => 'ניסיון חוזר #1, אחרי הכישלון הראשון',
        'subsequent_cycle' => 'מחזורי שחזור נוספים',
        'subsequent_cycle_sub' => 'מניסיון חוזר #2 והלאה',
        'rate' => 'שיעור שחזור',
        'attempted' => 'ניסיונות',
        'realized' => 'נגבו',
        'under' => 'בתהליך שחזור',
        'lost' => 'אבדו',
        'lost_breakdown' => 'פירוט האבודים',
        'lost_failed' => 'התשלום נכשל',
        'lost_stopped' => 'הושהה, בוטל או הסתיים',
        'lost_skipped' => 'דולג',
        'lost_line' => 'אבדו :lost: התשלום נכשל :failed · הושהה, בוטל או הסתיים :stopped · דילוגים לא נמדדים',
    ],

    'sources' => [
        'title' => 'התפלגות לפי מקור תשלום',
        'subtitle' => 'היכן נגבה כל חיוב, בתקופה שנבחרה',
        'source' => 'מקור',
        'attempted' => 'ניסיונות',
        'realized' => 'נגבו',
        'under' => 'בתהליך שחזור',
        'realization' => 'שיעור גבייה',
        'first_attempt' => 'הצלחה בניסיון ראשון',
        'first_cycle' => 'שחזור במחזור ראשון',
        'subsequent_cycle' => 'שחזור במחזורים נוספים',
        'shopify_note' => 'Shopify Payments מריצה ניסיונות חוזרים בעצמה ומדווחת רק את התוצאה הסופית, ולכן עמודות השחזור שלה ריקות.',
    ],

    'source' => [
        'payplus' => 'כרטיס שמור (PayPlus)',
        'shopify' => 'Shopify Payments',
    ],

    'country' => [
        'title' => 'התפלגות תשלומים לפי מדינה',
    ],

    'over_time' => [
        'title' => 'תשלומים לאורך זמן',
        'subtitle' => 'חיובים לפי יום הניסיון הראשון, ואיך כל אחד הסתיים',
        'first' => 'שולם בניסיון הראשון',
        'recovered' => 'שוחזר',
        'under' => 'בתהליך שחזור',
        'lost' => 'אבד',
    ],
];
