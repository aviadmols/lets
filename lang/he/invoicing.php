<?php

// חשבוניות — הטקסט שמודפס על מסמכים חשבונאיים אמיתיים מול הלקוח.
// מופרד מ-settings.php כי המחרוזות האלה נקראות על ידי רואה חשבון, לא בממשק הניהול.
// מראה של lang/en/invoicing.php — כל מפתח חייב להתקיים בשני הקבצים.
return [

    // תיאורי שורות במסמך, בשימוש רק כשלתוכנית אין שם פריט שמור.
    'line' => [
        'deposit' => 'מקדמה — תוכנית :reference',
        'installment' => 'תשלום לתוכנית תשלומים — תוכנית :reference',
        'final_installment' => 'תשלום אחרון — תוכנית :reference',
        'recurring' => 'תשלום מנוי — תוכנית :reference',
        'upsell' => 'רכישה נוספת — תוכנית :reference',
        'refund' => 'זיכוי — תוכנית :reference',
        'cancellation' => 'ביטול — תוכנית :reference',
        'platform_order' => 'הזמנה :reference',
        // מאזן את פירוט הפריטים לסכום ההזמנה (משלוח, עמלות, עיגול).
        'adjustment' => 'משלוח, עמלות והתאמות',
    ],

    // הערות חופשיות שמודפסות על המסמך, כדי שיתאים חזרה ל-LETS.
    'remarks' => [
        'plan' => 'תוכנית :reference',
        'order' => 'הזמנה :reference',
    ],
];
