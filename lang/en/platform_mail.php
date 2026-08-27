<?php

// Platform → Email delivery. The OWNER's screen only: the house's sending
// account, the address every shop without a verified domain sends as, and the
// domain that address must sit on.
// Mirror of lang/he/platform_mail.php — every key must exist in both files.
return [

    'title' => 'Email delivery',
    'intro' => 'All platform mail leaves through one SendGrid account — yours. A shop that has verified a domain of its own is sent as that domain; everything else is sent from the address set here.',

    'account' => [
        'heading' => 'The sending account',
        'intro' => 'The key is stored encrypted and never shown again. It can also be set as an environment variable (SENDGRID_API_KEY) — a key saved here wins over it.',

        'state' => 'State',
        'state_off' => 'Not connected — mail leaves through the mailer configured in the environment.',
        'state_on_saved' => 'Connected (key stored in the system).',
        'state_on_env' => 'Connected through the SENDGRID_API_KEY environment variable.',

        'key' => 'SendGrid API key',
        'key_help' => 'Paste a key with Mail Send and Domain Authentication permissions. In SendGrid: Settings → API Keys → Create API Key.',
        'key_stored' => 'A key is already stored. Leave blank to keep it, or paste a new one to replace it.',

        'from_address' => 'Platform sender address',
        'from_address_help' => 'Every shop that has not verified a domain of its own sends from this. It MUST sit on the domain authenticated below — otherwise SendGrid refuses those shops\' mail.',
        'from_name' => 'Sender name',

        'subdomain' => 'Signing subdomain',
        'subdomain_help' => 'The label domains are signed under: mail.example.co.il. Default: mail. Changing it affects only domains authenticated from now on.',
    ],

    'domain' => [
        'heading' => 'The platform domain',
        'intro' => 'The domain the sender address above sits on. Exactly the process merchants go through — add the CNAMEs, then verify.',

        'domain' => 'Your domain',
        'domain_help' => 'The bare domain, no https and no www — for example lets.co.il.',

        'needs_key' => 'Set an API key and save first — then a domain can be authenticated.',
        'none' => 'No domain authenticated yet. Type your domain and press "Create records".',

        'from_mismatch' => 'Careful: the sender address (:from) is not on the authenticated domain (:domain). SendGrid will refuse mail for every shop that has not verified a domain of its own. Fix the sender address, or authenticate the matching domain.',

        'requested' => 'Records created. Add them to your DNS, then press "Check records".',
        'verified_now' => 'The platform domain is verified.',
    ],

    'test' => [
        'action' => 'Send a test email',
        'field' => 'Which address should it go to?',
        'subject' => 'Delivery test — LETS',
        'body' => 'If you received this, platform email delivery is working.',
        'sent' => 'Sent to :email.',
        'failed' => 'The send failed. What the mail provider said:',
    ],

    'actions' => [
        'save' => 'Save',
    ],

    'saved' => 'Settings saved.',
];
