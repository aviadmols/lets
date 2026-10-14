<?php

/*
| Settings → Webhooks (app/Filament/Pages/ManageWebhooks.php). Mirrored in lang/he/webhooks.php.
*/
return [
    'title' => 'Webhooks',
    'intro' => 'Send every paid order and every recurring charge — successful or failed — as JSON to your own URL (Make, Zapier, n8n, your ERP).',

    'topic' => [
        'order.paid' => 'Order paid',
        'recurring_charge.succeeded' => 'Recurring charge succeeded',
        'recurring_charge.failed' => 'Recurring charge failed',
    ],
    'topic_help' => [
        'order.paid' => 'WooCommerce: every order on the site, once it is paid (processing / completed), whatever the payment method. Shopify: every order paid through LETS — a deposit, a thank-you upsell, an add-on from the account area.',
        'recurring_charge.succeeded' => 'A subscription cycle or an installment was charged.',
        'recurring_charge.failed' => 'A subscription cycle or an installment was declined — including whether and when we will retry.',
    ],

    'col' => [
        'name' => 'Name',
        'topics' => 'Sends',
        'active' => 'Active',
        'health' => 'Last delivery',
    ],

    'field' => [
        'name' => 'Name',
        'name_placeholder' => 'e.g. Make — orders to Google Sheets',
        'url' => 'URL',
        'url_help' => 'A public https:// address. We POST a signed JSON to it and do not follow redirects.',
        'topics' => 'Events to send',
        'active' => 'Active',
        'topic' => 'Event',
        'origin' => 'Data',
    ],

    'origin' => [
        'real' => 'My latest real event (falls back to an example)',
        'example' => 'Made-up example',
    ],

    'action' => [
        'create' => 'Add webhook',
        'test' => 'Send test',
        'secret' => 'Signing secret',
        'rotate' => 'Rotate secret',
        'close' => 'Close',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'download' => 'Download .json',
        'view_json' => 'JSON',
        'resend' => 'Resend',
    ],

    'health' => [
        'ok' => 'Delivered :when',
        'failing' => ':count failed in a row',
        'never' => 'Nothing sent yet',
    ],

    'empty' => [
        'title' => 'No webhooks yet',
        'body' => 'Add a URL to start receiving orders and recurring charges as JSON.',
    ],

    'secret' => [
        'heading' => 'Signing secret',
        'body' => 'Use this secret to verify the X-Lets-Signature header on every request. Keep it private.',
        'rotate_warning' => 'The old secret stops working immediately. Update your receiver right after rotating.',
    ],

    'signing' => [
        'title' => 'How requests are signed',
        'body' => 'Every request is a POST with a JSON body and the headers X-Lets-Topic, X-Lets-Event-Id and X-Lets-Signature. Recompute the signature over the RAW body with your secret and compare; reject a timestamp older than 5 minutes.',
        'retries' => 'A delivery that does not get a 2xx answer within 10 seconds is retried after 1 minute, 5 minutes, 30 minutes, 2 hours and 6 hours. event_id stays the same on every retry — use it to ignore duplicates.',
    ],

    'generator' => [
        'title' => 'Test JSON',
        'body' => 'Build a sample for any event to see exactly what your URL will receive. Samples carry "test": true and a fresh event_id.',
        'generate' => 'Generate',
        'built_from' => [
            'real' => 'Built from your latest real event.',
            'example' => 'A made-up example — no matching real event yet.',
        ],
    ],

    'log' => [
        'title' => 'Requests sent',
        'intro' => 'Every request we sent, and whether your URL received it (answered 2xx). A request that was not received is retried automatically; you can also resend it.',
        'filter' => [
            'all' => 'All',
            'received' => 'Received',
            'not_received' => 'Not received',
        ],
        'order' => 'Order #:number',
        'no_answer' => 'No answer: :error',
        'received_at' => 'Received :when',
        'next_attempt' => 'Next try :when',
        'sent_body' => 'What we sent',
        'newer' => 'Newer',
        'older' => 'Older',
        'page' => 'Page :page',
        'empty' => 'Nothing has been sent yet.',
        'test' => 'Test',
        'attempts' => ':count attempt(s)',
        'response_body' => 'Response',
        'not_built' => 'Not built yet — it is built on the first attempt.',
        'col' => [
            'when' => 'When',
            'endpoint' => 'Webhook',
            'subject' => 'Order / customer',
            'topic' => 'Event',
            'status' => 'Status',
            'response' => 'Your URL answered',
        ],
    ],

    'status' => [
        'pending' => 'Sending',
        'retrying' => 'Not received — retrying',
        'succeeded' => 'Received',
        'failed' => 'Not received',
    ],

    'notice' => [
        'saved' => 'Webhook saved',
        'url_refused' => 'This URL cannot be used',
        'url_refused_body' => 'Use a public https:// address on the standard port. Local, private and internal addresses are blocked.',
        'rotated' => 'Secret rotated',
        'test_sent' => 'Test delivered',
        'test_failed' => 'Test not delivered',
        'resent' => 'Delivered',
        'resend_failed' => 'Not delivered',
        'http_status' => 'Your URL answered HTTP :status.',
        'no_response' => 'No answer: :error',
    ],

    'sample' => [
        'customer_name' => 'Dana Cohen',
        'first_name' => 'Dana',
        'last_name' => 'Cohen',
        'street' => '1 Herzl St',
        'city' => 'Tel Aviv',
        'product_title' => 'Coffee beans — monthly',
        'decline' => 'Card declined',
    ],
];
