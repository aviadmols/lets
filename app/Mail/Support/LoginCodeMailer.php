<?php

namespace App\Mail\Support;

use App\Models\Shop;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * The mailer the personal area's sign-in code leaves through.
 *
 * When the merchant connected their own Resend account for the code
 * (MailTransport::loginCodeRelay), an on-demand Mailer is built from that
 * config ARRAY — the same Mail::build() route CampaignMailer takes — so nothing
 * global is touched: the shop's Resend key is never written into the shared
 * `smtp` mailer, and no other email sent later in the same process can leave
 * through it.
 *
 * Without one, the code goes exactly where it always went: the request-time
 * ladder (MailSettingsConfigurator) and the default mailer.
 */
final class LoginCodeMailer
{
    /**
     * Typed as the CONTRACT: under Mail::fake() the facade hands back a MailFake,
     * which satisfies the contract and nothing else.
     */
    public static function for(Shop $shop): Mailer
    {
        $relay = MailTransport::loginCodeRelay($shop);

        if ($relay === null) {
            MailSettingsConfigurator::apply($shop);

            return Mail::mailer();
        }

        $mailer = Mail::build($relay['config']);
        $mailer->alwaysFrom($relay['from']['address'], $relay['from']['name']);

        return $mailer;
    }

    /** Where the code is handed for delivery, for a log line or a toast. */
    public static function relayName(Shop $shop): string
    {
        $chosen = MailTransport::forLoginCode($shop);

        return (string) ($chosen['config']['host'] ?? config('mail.default'));
    }
}
