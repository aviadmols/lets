<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Per-shop credentials encryption key
    |--------------------------------------------------------------------------
    | Dedicated key (separate from APP_KEY) used by App\Casts\EncryptedCredentials
    | to encrypt each shop's PayPlus credentials. Rotating this does NOT affect
    | session/cookie encryption. Accepts a raw 32-byte key or a base64:... value.
    */
    'credentials_key' => env('TENANT_CREDENTIALS_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Previous credentials keys (rotation)
    |--------------------------------------------------------------------------
    | Mirrors APP_PREVIOUS_KEYS (config/app.php): a comma-separated list of keys
    | that can still DECRYPT a shop's credential bag, tried in order after
    | `credentials_key` fails, so a rotation does not silently disconnect every
    | shop's PayPlus/WooCommerce/invoicing credentials the moment it lands.
    | Run `php artisan tenant:rotate-credentials-key` after adding the OLD key
    | here and generating a new TENANT_CREDENTIALS_KEY, to re-encrypt every shop
    | under the new key — then this list can be emptied.
    */
    'previous_credentials_keys' => array_values(array_filter(
        explode(',', (string) env('TENANT_CREDENTIALS_PREVIOUS_KEYS', ''))
    )),
];
