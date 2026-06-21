<?php

// Mail settings page labels (Settings → Mail). The EMAIL BODIES live in
// App\Support\DefaultEmailTemplates, not here — this file is UI chrome only.
// Mirror in lang/he/mail.php.
return [
    'title' => 'Email notifications',
    'intro' => 'Customize the emails your customers receive. Leave a template blank to use the default.',

    'template' => [
        'first_payment_welcome' => 'First payment welcome',
        'recurring_payment_reminder' => 'Upcoming charge reminder',
        'manual_recurring_payment' => 'Manual payment request',
        'charge_succeeded' => 'Payment received',
        'charge_failed' => 'Payment failed',
        'plan_cancelled' => 'Plan cancelled',
    ],

    'field' => [
        'subject' => 'Subject',
        'body' => 'Email body (HTML)',
        'subject_hint' => 'Leave blank to use the default subject.',
        'body_hint' => 'Leave blank to use the default email. Placeholders are replaced as plain text — Blade/PHP is never executed.',
        'placeholders' => 'Available placeholders',
    ],

    'reminder' => [
        'heading' => 'Reminders',
        'enabled' => 'Send an upcoming-charge reminder',
        'offset_hours' => 'Hours before the charge',
        'offset_help' => 'How many hours before the next charge to email the reminder.',
    ],

    'smtp' => [
        'heading' => 'Send from your own mailbox (SMTP)',
        'intro' => 'Optional. When off, emails are sent from the platform mailer.',
        'override' => 'Use my own SMTP server',
        'host' => 'SMTP host',
        'port' => 'Port',
        'encryption' => 'Encryption',
        'username' => 'Username',
        'password' => 'Password',
        'password_hint' => 'Saved encrypted. Paste a new value to replace it.',
        'from_address' => 'From address',
        'from_name' => 'From name',
    ],

    'portal' => [
        'store_page_url' => 'Customer portal page URL',
        'store_page_help' => 'The storefront page customers land on from email links.',
    ],

    'preview' => [
        'heading' => 'Preview',
        'note' => 'Preview uses sample data. Placeholders show as plain text.',
        'using_custom' => 'Showing your custom template.',
        'using_default' => 'Showing the default template.',
    ],

    'actions' => [
        'save' => 'Save email settings',
        'reset' => 'Reset to default',
        'send_test' => 'Send test email',
    ],

    'saved' => 'Email settings saved.',
];
