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

    /*
    |--------------------------------------------------------------------------
    | One subdomain per shop (docs/plans/shop-subdomains.md)
    |--------------------------------------------------------------------------
    | `admin_root_host` is the platform root: the login chooser, platform
    | screens and every machine endpoint. A shop's admin lives on
    | `<handle>.<admin_root_host>`. Locally use e.g. app.lets.localhost (Chrome
    | and Edge resolve *.localhost to 127.0.0.1 with no hosts-file edit).
    |
    | `subdomains_enabled` is the rollout switch: OFF (the default), nothing
    | resolves a shop from the host and every admin link is built on APP_URL as
    | before. Turn it ON only after the wildcard DNS record + certificate answer
    | (`curl -I https://anything.<root>/up` → 200). It also turns on the shared
    | session cookie domain (config/session.php).
    |
    | `extra_trusted_hosts`: comma-separated exact hosts to trust besides the
    | root, its subdomains, APP_URL's host, Railway and loopback.
    */
    'admin_root_host' => env('ADMIN_ROOT_HOST', 'app.lets.co.il'),

    'subdomains_enabled' => (bool) env('SHOP_SUBDOMAINS_ENABLED', false),

    'extra_trusted_hosts' => array_values(array_filter(
        explode(',', (string) env('TRUSTED_HOSTS_EXTRA', ''))
    )),
];
