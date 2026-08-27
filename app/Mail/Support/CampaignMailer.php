<?php

namespace App\Mail\Support;

use App\Models\Shop;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * The mailer ONE SHOP'S mail goes out through — built per send, never written
 * into the shared config.
 *
 * WHY NOT MailSettingsConfigurator::apply(): that helper Config::set()s the
 * chosen relay into `mail.mailers.smtp` and flips `mail.default`. On a web
 * request that dies with the request. On a QUEUE WORKER it does not — and
 * Laravel caches the resolved `smtp` mailer instance, so the first shop to send
 * on a worker would own that transport for every later job, shop B's mail
 * leaving through shop A's relay. A campaign is the first feature that sends
 * bulk mail from jobs, so it is the first that must not do that.
 *
 * Mail::build() makes an on-demand Mailer from a config ARRAY: nothing global is
 * touched, nothing is cached under a shared name, and the object is dropped when
 * the job ends.
 *
 * WHICH relay and WHAT From is not decided here — MailTransport owns that
 * ladder (the merchant's own SMTP, then the platform's SendGrid account, then
 * the platform default), so the queue-time path and the request-time one can
 * never disagree about where a shop's mail leaves from.
 */
final class CampaignMailer
{
    /**
     * The mailer to send THIS shop's mail through.
     *
     * Typed as the CONTRACT, not the concrete mailer: under Mail::fake() the
     * facade hands back a MailFake, which satisfies the contract and nothing
     * else — a concrete type here would make every campaign send explode in the
     * test suite and, worse, be caught by the job's own error handling.
     */
    public static function for(Shop $shop): Mailer
    {
        $chosen = MailTransport::for($shop);

        if ($chosen === null) {
            return Mail::mailer();
        }

        $mailer = Mail::build($chosen['config']);

        if ($chosen['from'] !== null) {
            $mailer->alwaysFrom($chosen['from']['address'], $chosen['from']['name']);
        }

        return $mailer;
    }

    /** The From the mailable should stamp, or null for the platform default. */
    public static function fromFor(Shop $shop): ?array
    {
        return MailTransport::for($shop)['from'] ?? null;
    }
}
