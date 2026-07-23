<?php

// תשלומים ← מנויי Shopify (מסלול הפיילוט של Shopify Payments). הסטטוסים משקפים
// את אוצר המילים של SHOPIFY — שני המסלולים לעולם לא חולקים enum.
// מראה של lang/en/shopify_subscriptions.php — כל מפתח חייב להתקיים בשניהם.
return [

    'empty' => 'אין עדיין מנויי Shopify. הם יופיעו כאן כשלקוח יירשם למנוי בקופה.',

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
        'pause' => 'השהיה',
        'resume' => 'חידוש',
        'cancel' => 'ביטול',
        'cancel_body' => 'המנוי מבוטל ב-Shopify והלקוח לא יחויב שוב. לא ניתן לבטל את הפעולה מכאן — מנוי חדש דורש רכישה חדשה בקופה.',
        'done' => 'בוצע — Shopify החילה את השינוי.',
        'failed' => 'Shopify לא החילה את השינוי',
    ],

    'reason' => [
        'shopify_rejected' => 'Shopify דחתה את הבקשה. ייתכן שהחוזה השתנה — רעננו ונסו שוב.',
        'transport' => 'לא ניתן להגיע ל-Shopify. נסו שוב בעוד רגע.',
        'not_found' => 'Shopify כבר לא מזהה את החוזה הזה.',
        'bad_date' => 'בחרו תאריך עתידי.',
    ],
];
