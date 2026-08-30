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

    /*
    | Amazon SES — the second sending provider. Every value here is a FALLBACK:
    | what the owner saved on Platform → Email delivery wins, exactly as it does
    | for SendGrid, so a fresh environment can still come up from variables
    | alone.
    |
    | The two credential pairs are NOT interchangeable. The access key signs the
    | domain-identity API calls; the SMTP pair is generated separately in the
    | SES console and is the only one that can actually send.
    */
    'ses' => [
        'region' => env('SES_REGION', env('AWS_DEFAULT_REGION')),
        'key' => env('SES_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
        'secret' => env('SES_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
        'smtp_username' => env('SES_SMTP_USERNAME'),
        'smtp_password' => env('SES_SMTP_PASSWORD'),
        'smtp_port' => (int) env('SES_SMTP_PORT', 587),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
