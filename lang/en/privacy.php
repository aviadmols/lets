<?php

// The public privacy notice + processor terms served at /privacy.
//
// EVERY claim here must stay TRUE of the code. It is the document Shopify's
// review reads, and the one a merchant's DPA points at — a sentence that drifts
// from what the app does is a compliance defect, not a copy defect. When the
// data flow changes (a new sub-processor, a new retention rule), change this in
// the SAME commit. Mirror every key in lang/he/privacy.php.
return [

    'title' => 'Privacy & data protection',
    'subtitle' => 'How the LETS app handles personal data on behalf of the merchants who install it.',
    'updated' => 'Last updated: :date',
    'intro' => 'LETS is a Shopify app that runs subscriptions, deposits with installments, and post-purchase offers for merchants. To do that it must read and store some personal data belonging to the merchant’s customers. This page explains exactly what, why, for how long, and who else can see it.',

    'roles' => [
        'heading' => 'Who is responsible for the data',
        'body' => 'The merchant who installs LETS is the **data controller**: it is their store, their customers, and their decision what to sell and how to bill it. LETS is a **data processor** acting on the merchant’s instructions — we process personal data only to provide the app’s functions, never for our own purposes.',
    ],

    'collect' => [
        'heading' => 'What we process',
        'intro' => 'Only what a billing app cannot work without:',
        'items' => [
            'Store details — the myshopify domain, store name, and the access token Shopify issues at install (stored encrypted).',
            'Customer identity — name, email, phone and address, taken from the order. Used to bill the right person, send them receipts and payment notices, and let them manage their own subscription.',
            'Order and plan data — line items, amounts, currency, billing schedule, charge history, and the resulting invoices.',
            'Payment references — a gateway token or a Shopify subscription contract id. **Card numbers never reach LETS**; they stay with the payment provider.',
            'Consent records — when and on what terms a customer agreed to future charges, because a recurring charge without a recorded consent is not permitted by this app.',
        ],
    ],

    'purposes' => [
        'heading' => 'Why we process it',
        'items' => [
            'To take the payments the customer agreed to — the deposit, each installment, each subscription cycle.',
            'To tell people what happened to their money: receipts, upcoming-charge reminders, failed-payment notices.',
            'To issue accounting documents where the merchant has enabled invoicing.',
            'To show the merchant their own subscriptions, orders and errors inside the app.',
            'To offer a relevant post-purchase product, based on what was just bought.',
        ],
        'never' => 'We do not sell personal data, we do not share it for advertising, and we do not use one merchant’s data to serve another.',
    ],

    'sharing' => [
        'heading' => 'Who else processes it',
        'intro' => 'Sub-processors, each receiving only what its job requires:',
        'items' => [
            'Shopify — the source of the store, order and customer data, and the processor of Shopify-Payments subscriptions.',
            'PayPlus — the payment gateway for merchants who bill through it. It holds the card and the token; we hold only the reference.',
            'Green Invoice (Morning) — accounting documents, and only for merchants who switch invoicing on.',
            'Railway — hosting and the database, where the app and its data run.',
            'The merchant’s email provider — delivery of the transactional emails listed above.',
        ],
        'outro' => 'We disclose personal data to no one else, except where the law compels it.',
    ],

    'automated' => [
        'heading' => 'Automated charges',
        'body' => 'The app charges automatically: that is its purpose. Every future charge requires a consent record made when the customer signed up, and every schedule is visible to them. A customer can pause, skip or cancel at any time from the customer portal or by asking the merchant — and a cancelled plan is never charged again. No profiling, scoring or automated decision about a person is performed beyond executing the billing schedule they agreed to.',
    ],

    'security' => [
        'heading' => 'How it is protected',
        'items' => [
            'In transit — HTTPS is enforced on every request, including all webhooks and gateway callbacks.',
            'At rest — each store’s gateway credentials and access tokens are encrypted at the application layer, on top of the hosting provider’s disk encryption.',
            'Isolation — every record carries the store it belongs to and every query is scoped to it, so one merchant’s data cannot be read from another’s session.',
            'Card data — never stored, never logged, never seen by the app.',
        ],
    ],

    'retention' => [
        'heading' => 'How long we keep it',
        'items' => [
            'While the app is installed, for as long as the subscription or installment plan is live and the merchant needs its history.',
            'When a merchant uninstalls, Shopify sends a shop-redaction request about 48 hours later and the store’s data is erased.',
            'When a merchant or a customer asks for erasure, Shopify sends a customer-redaction request and that customer’s personal data is erased for that store.',
            'Accounting documents already issued are kept for the period tax law requires, because deleting them is not lawful.',
        ],
    ],

    'rights' => [
        'heading' => 'Your rights',
        'body' => 'If you are a shopper, your relationship is with the store you bought from — ask them, and they can obtain your data or have it erased through Shopify, which reaches us automatically. If you are a merchant, you can raise the same requests directly. Depending on where you live, you may have the right to access, correct, export, restrict, object to, or delete your personal data.',
    ],

    'contact' => [
        'heading' => 'Contact',
        'body' => 'Questions about this notice or a data request: :email',
    ],

    'back' => 'Back to the app',
];
