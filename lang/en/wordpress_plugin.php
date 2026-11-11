<?php

// Settings → WordPress plugin (App\Filament\Pages\ManageWordPressPlugin).
return [
    'nav' => 'WordPress plugin',
    'title' => 'WordPress plugin',
    'intro' => 'The LETS plugin connects your WooCommerce store. Download the newest version here and install it over the one you have.',

    'versions' => [
        'heading' => 'Version',
        'latest' => 'Newest version',
        'installed' => 'Installed on your store',
        'checking' => 'Checking your store…',
    ],

    'state' => [
        'current' => 'Up to date',
        'outdated' => 'Update available',
        'unknown' => 'Could not check',
    ],

    'download' => 'Download plugin :version',

    'install' => [
        'heading' => 'How to install',
        'step_download' => 'Download the plugin file (a .zip) with the button above.',
        'step_upload' => 'In WordPress: Plugins → Add New → Upload Plugin, and choose the file.',
        'step_replace' => 'When WordPress asks, choose "Replace current with uploaded".',
        'step_done' => 'Check that the plugins list shows the new version.',
        'keeps_connection' => 'Your connection to LETS and your settings are kept. There is no need to reconnect.',
    ],
];
