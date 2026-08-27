<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SendGrid — the PLATFORM's sending account
    |--------------------------------------------------------------------------
    |
    | ONE account, ours, for every shop that has not brought its own relay. The
    | key is an env var and not a database row on purpose: it is the platform's
    | credential (the house rule — per-shop secrets are encrypted in the DB,
    | platform secrets are env), and a leaked one would let somebody send as
    | every merchant we host.
    |
    | Mail leaves over SendGrid's SMTP relay (the username is the literal string
    | "apikey"; the password is the key). Domain authentication — the CNAMEs a
    | merchant adds so their mail is signed as their own domain — goes over the
    | v3 API with the same key.
    |
    | `from_address` is the fallback envelope for a shop with no verified domain
    | of its own; it MUST be on a domain this account has authenticated, or
    | SendGrid refuses the message.
    |
    */
    'sendgrid' => [
        'api_key' => env('SENDGRID_API_KEY'),
        'api_base' => env('SENDGRID_API_BASE', 'https://api.sendgrid.com/v3'),
        'smtp_host' => env('SENDGRID_SMTP_HOST', 'smtp.sendgrid.net'),
        'smtp_port' => (int) env('SENDGRID_SMTP_PORT', 587),
        'smtp_username' => env('SENDGRID_SMTP_USERNAME', 'apikey'),
        'from_address' => env('SENDGRID_FROM_ADDRESS'),
        'from_name' => env('SENDGRID_FROM_NAME'),
        /** The label under a merchant's domain: mail.theirshop.co.il. */
        'subdomain' => env('SENDGRID_SUBDOMAIN', 'mail'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
