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
];
