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
        'login_code' => 'Sign-in code',
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
        'offset_option' => ':hours hours before',
    ],

    'smtp' => [
        'heading' => 'Send from your own mailbox (SMTP)',
        'intro' => 'Optional. When off, emails are sent from the platform mailer.',
        'override' => 'Use my own SMTP server',
        'host' => 'SMTP host',
        'port' => 'Port',
        'encryption' => 'Encryption',
        'encryption_tls' => 'TLS',
        'encryption_ssl' => 'SSL',
        'username' => 'Username',
        'password' => 'Password',
        'password_hint' => 'Saved encrypted. Paste a new value to replace it.',
        'password_saved' => 'Saved — paste a new value to replace it.',
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
        // The Timeline preview: this customer's real details, filled into the
        // template as it stands today — not a stored copy of the sent message.
        'note_plan' => 'Filled with this customer’s details, using the template as it is now. Anything the send recorded and the plan no longer holds appears blank.',
        'using_custom' => 'Showing your custom template.',
        'using_default' => 'Showing the default template.',
        'close' => 'Close',
    ],

    'reset' => [
        'heading' => 'Restore the default template?',
        'body' => 'This clears your custom subject and body for this email. The platform default will be used.',
    ],

    'test' => [
        'template' => 'Template to test',
        'recipient' => 'Send to',
        'sent' => 'Test email sent to :email.',
        'failed' => 'Could not send the test email (:reason).',
    ],

    /*
     * WHEN each email goes out. A merchant scanning this list needs to know what
     * triggers a template before they decide whether to reword it — the list is a
     * map of the customer's journey, not six identical boxes.
     */
    'trigger' => [
        'first_payment_welcome' => 'Right after the first payment succeeds',
        'recurring_payment_reminder' => 'A set number of hours before each charge',
        'manual_recurring_payment' => 'When a cycle needs the customer to pay by hand',
        'charge_succeeded' => 'After every successful charge',
        'charge_failed' => 'When a charge is declined',
        'plan_cancelled' => 'When a plan is cancelled, by you or by the customer',
        'login_code' => 'When a customer asks to sign in with a code',
    ],

    'state' => [
        'default' => 'Using the default',
        'custom' => 'Customised',
    ],

    // The sending domain — mail leaves through the PLATFORM's sending account,
    // signed as the shop's own domain.
    'sender' => [
        'heading' => 'The domain your email is sent from',
        'intro' => 'By default your email leaves from a LETS address. You can have it signed as your own domain instead — your customers see your name, and the mail lands in the inbox rather than in spam. You do not need an email service of your own: sending still goes through our account; you only add a few DNS records.',
        'platform_off' => 'The platform sending service is not connected yet. Talk to us and we will set it up — until then your email keeps going out as before.',

        'domain' => 'Your domain',
        'domain_help' => 'The bare domain, no https and no www — for example example.co.il. We sign the mail under mail.example.co.il.',

        'none' => 'No domain set yet. Type your domain and press "Create records" — we will hand you the DNS records to add.',
        'pending_body' => 'Add the records below at your DNS provider (your registrar or DNS host — Cloudflare, GoDaddy, Namecheap and so on), then press "Check records". Until it verifies, your email keeps going out from the LETS address as before.',
        'verified_body' => 'Verified. Your shop\'s email is sent and signed as :domain.',

        'records_help' => 'Add these as CNAME records. Paste the middle column into "Name"/Host — some providers want only the part before your domain (em1234 rather than em1234.example.co.il). Paste the last column into "Value"/Points to. Do not proxy them (on Cloudflare the cloud must be grey, not orange).',
        'propagation' => 'DNS changes take anywhere from minutes to 48 hours. If a check still says "missing", wait a little and check again — there is no need to create the records afresh.',
        'checked_at' => 'Last checked: :when',

        'status' => [
            'pending' => 'Waiting for verification',
            'verified' => 'Verified',
            'failed' => 'Records not found yet',
        ],

        'col' => [
            'type' => 'Type',
            'host' => 'Name / Host',
            'value' => 'Value / Points to',
            'state' => 'State',
        ],

        'record' => [
            'live' => 'Found',
            'missing' => 'Missing',
        ],

        'action' => [
            'request' => 'Create records',
            'rerequest' => 'Change the domain',
            'check' => 'Check records',
            'remove' => 'Remove the domain',
            'remove_confirm' => 'Remove the domain? Your email goes back to leaving from the LETS address.',
        ],

        'requested' => 'Records created. Add them to your DNS, then press "Check records".',
        'verified_now' => 'The domain is verified. Your email now goes out as your own.',
        'removed' => 'The domain was removed.',

        'reason' => [
            'not_configured' => 'The platform sending service is not connected yet — talk to us.',
            'provider_unreachable' => 'We could not reach the sending service just now. Try again in a moment; nothing is broken.',
            'records_missing' => 'Some records are not in your DNS yet. The table shows which — DNS changes can take up to 48 hours.',
            'invalid_domain' => 'That does not look like a domain. Type the domain on its own, for example example.co.il.',
        ],
    ],

    'locale' => [
        'heading' => 'Language',
        'label' => 'Your customers read email in',
        'help' => 'Changes the default wording and the reading direction. Templates you have customised keep your own text.',
        'he' => 'Hebrew',
        'en' => 'English',
    ],

    'sample' => [
        'customer_name' => 'Dana Cohen',
        'business_name' => 'My Store',
        'product_title' => 'Monthly subscription',
        'failure_reason' => 'Card declined (insufficient funds)',
        'cancellation_reason' => 'Cancelled at your request.',
    ],

    'edit' => [
        'heading' => 'The emails your customers receive',
        'intro' => 'Each one shows the wording that goes out today. Edit it to make it yours, or restore the default at any time.',
        'advanced' => 'Advanced',
        'advanced_intro' => 'Reminder timing and your own mail server. Most shops never need to open this.',
    ],
    'actions' => [
        'save' => 'Save email settings',
        'reset' => 'Restore default',
        'preview' => 'Preview',
        'send_test' => 'Send test email',
    ],

    'saved' => 'Email settings saved.',
    'reset_done' => 'Template restored to the default.',
];
