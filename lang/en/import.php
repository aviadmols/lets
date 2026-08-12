<?php

// Subscription CSV import + export. Mirror every key in lang/he/import.php.
return [
    'title' => 'Import subscriptions',
    'nav' => 'Import / Export',

    'intro' => 'Bring your existing subscribers in from a spreadsheet. The whole file is checked first — nothing is written until every row is valid.',

    'action' => [
        'choose_file' => 'Choose CSV file',
        'dry_run' => 'Check the file',
        'commit' => 'Import now',
        'export' => 'Export subscriptions (CSV)',
        'template' => 'Download an empty template',
    ],

    'option' => [
        'start_charging' => 'Schedule these subscriptions to charge',
        'start_charging_help' => 'Off: subscriptions land with no next charge date and nothing bills until you say so. On: the next charge is taken from the period dates in the file.',
        'default_product' => 'Product ID for rows without one',
        'currency' => 'Currency for rows without one',
        'date_format' => 'Date format (leave empty to detect)',
    ],

    'report' => [
        'title' => 'File check',
        'rows' => 'Rows read',
        'valid' => 'Valid',
        'invalid' => 'Invalid',
        'creates' => 'New subscriptions',
        'updates' => 'Updates to existing',
        'written' => 'Written',
        'scheduled' => 'Will be scheduled to charge',
        'money' => 'Charging in the next :days days',
        'tokens' => 'Cards to vault',
        'consents' => 'Consent records transcribed',
        'clean' => 'Every row is valid. This file is ready to import.',
        'blocked' => ':count rows cannot be imported. Nothing will be written until they are fixed.',
        'hidden' => 'and :count more of the same kind',
        'unknown_headers' => 'Columns we do not use and will ignore: :columns',
        'errors' => 'Problems',
        'warnings' => 'Worth knowing',
        'line' => 'Line :line',
        'empty' => 'Choose a file to check it.',
        'running' => 'Importing… this page updates itself.',
        'columns' => 'The columns this file can carry',
    ],

    'abort' => [
        'invalid_rows' => 'Nothing was imported: :count rows are invalid.',
        'missing_header' => 'The file has no :column column, so no row can be matched to a subscription.',
        'no_rows' => 'The file has a header but no rows.',
    ],

    'error' => [
        'no_key' => 'No membership_id and no public_id — this row cannot be matched to a subscription.',
        'email' => 'Not a valid email address: :value',
        'amount' => 'The :column column is not a number: :value',
        'negative_amount' => 'The amount is negative: :value',
        'currency' => 'Not a 3-letter currency code: :value',
        'date' => 'The :column column is not a date we can read: :value',
        'status' => 'Unknown status: :value',
        'plan_kind' => 'Unknown plan kind: :value (expected installments or recurring)',
        'cycle' => 'Unknown billing cycle: :value',
        'exp_month' => 'Card expiry month out of range: :value',
        'exp_year' => 'Card expiry year out of range: :value',
        'expires_before_starts' => 'expires_at is earlier than starts_at.',
        'duplicate' => 'The same subscription appears earlier in this file, on line :line.',
        'unknown_public_id' => 'No subscription in this store has public_id :value.',
        'kind_change' => 'This subscription is :from and the file says :to — a live plan cannot change kind.',
        'illegal_transition' => 'A subscription cannot move from :from to :to.',
        'status_required' => 'A new subscription needs a status.',
        'amount_required' => 'A new subscription needs a plan_amount.',
        'cycle_required' => 'A new recurring subscription needs a cycle.',
        'total_required' => 'A new installments plan needs a total_amount.',
        'product_required' => 'A new subscription needs a product_id (or set a default for the whole file).',
        'token_required' => 'This subscription is set to keep charging but carries no card_token.',
    ],

    'warn' => [
        'no_identity' => 'No email, phone or person_id — this customer cannot be contacted or sign in to their account.',
        'no_token' => 'No card_token: this subscription can be recorded, but it can never charge.',
        'card_expired' => 'The card on file expired in :month/:year.',
        'last_four' => 'last_4_digits is not four digits: :value',
        'unknown_product' => 'Product :value is not in this store\'s synced catalog.',
        'cancel_conflict' => 'The row is marked cancelled but its status says :status — importing it as cancelled.',
    ],

    'cli' => [
        'dry_run_only' => 'This was a check only. Re-run with --commit to write it.',
        'written' => 'Imported :count subscriptions.',
        'exported' => 'Exported :count subscriptions to :path',
    ],
];
