<?php

namespace App\Mail\Support;

use App\Models\Shop;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

/**
 * Applies a SENDING shop's chosen relay into the live mail config for the
 * duration of a single REQUEST-TIME send.
 *
 * The choice itself — the merchant's own SMTP, then the platform's SendGrid
 * account, then the platform .env mailer — belongs to MailTransport, so this
 * path and the queue-time one (CampaignMailer) can never disagree about where a
 * shop's mail leaves from.
 *
 * Per-shop + runtime-scoped: the override is set just before a send and never
 * persisted into the shared config file, so two shops sending on the same
 * request never cross From addresses or credentials.
 *
 * ON A QUEUE WORKER, USE CampaignMailer INSTEAD. Laravel caches the resolved
 * `smtp` mailer, and a worker does not end when a job does — the first shop to
 * send would own the transport for every later job. This helper is only safe
 * where the process ends with the request.
 */
final class MailSettingsConfigurator
{
    public static function apply(Shop $shop): void
    {
        $chosen = MailTransport::for($shop);

        if ($chosen === null) {
            return; // platform .env mailer
        }

        $config = $chosen['config'];

        Config::set('mail.mailers.smtp.host', $config['host']);
        Config::set('mail.mailers.smtp.port', $config['port']);
        Config::set('mail.mailers.smtp.username', $config['username']);
        Config::set('mail.mailers.smtp.password', $config['password']);

        // Unconditionally — a scheme left over from a previous apply() in the
        // same process must not leak onto a shop that chose no encryption.
        Config::set('mail.mailers.smtp.scheme', $config['scheme'] ?? null);

        // A stray MAIL_URL would silently OUTRANK everything above: Laravel's
        // smtp transport prefers the DSN when `url` is set, and the merchant's
        // relay would be ignored with no error anywhere.
        Config::set('mail.mailers.smtp.url', null);

        if ($chosen['from'] !== null) {
            Config::set('mail.from.address', $chosen['from']['address']);
            Config::set('mail.from.name', $chosen['from']['name']);
        }

        // Force the SMTP mailer for this send (the platform default may be `log`).
        Config::set('mail.default', 'smtp');

        // Laravel caches resolved mailers BY NAME: if anything in this process
        // already resolved `smtp`, every Config::set above is a silent no-op and
        // the send leaves through whatever transport (and From) was cached.
        // Purging makes the next resolve read the config just written.
        Mail::purge('smtp');
    }
}
