<?php

// One subdomain per shop (<handle>.app.lets.co.il) — handle, host resolution,
// and the links that lead to a shop's admin. English is the default; mirror
// EVERY key in lang/he/tenancy.php.
return [
    'handle' => [
        'label' => 'Store address',
        'edit' => [
            'action' => 'Change address',
            'heading' => 'Change the store address',
            'intro' => 'The store admin moves to the new address. The old address keeps redirecting to it for :days days.',
            'help' => 'Lowercase letters, digits and dashes, 3–63 characters. The admin opens at <address>.:root',
            'submit' => 'Save address',
            'saved' => 'Saved. The store admin is now at :url',
        ],
        'error' => [
            'too_short' => 'The address must be at least 3 characters.',
            'format' => 'Use lowercase letters, digits and dashes only, starting and ending with a letter or digit (up to 63 characters).',
            'reserved' => 'That address is reserved for the platform. Choose another.',
            'taken' => 'Another store already uses that address.',
        ],
    ],
    'admin_url' => [
        'label' => 'Admin URL',
    ],
    'open' => [
        'action' => 'Open',
    ],
    'wall' => [
        'signed_out' => 'You were signed out',
        'signed_out_body' => 'That address belongs to a different store. Sign in to your own store here.',
    ],
    'no_such_store' => [
        'title' => 'No such store',
        'body' => 'There is no store at :host. Check the address you were given.',
        'hint' => 'Go to the LETS sign-in page',
    ],
];
