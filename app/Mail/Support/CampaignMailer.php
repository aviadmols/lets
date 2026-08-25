<?php

namespace App\Mail\Support;

use App\Models\MerchantMailSettings;
use App\Models\Shop;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * The mailer ONE SHOP'S campaign email goes out through — built per send, never
 * written into the shared config.
 *
 * WHY NOT MailSettingsConfigurator::apply(): that helper Config::set()s the
 * shop's SMTP host into `mail.mailers.smtp` and flips `mail.default`. On a web
 * request that dies with the request. On a QUEUE WORKER it does not — and
 * Laravel caches the resolved `smtp` mailer instance, so the first shop to send
 * on a worker would own that transport for every later job, shop B's mail
 * leaving through shop A's relay. A campaign is the first feature that sends
 * bulk mail from jobs, so it is the first that must not do that.
 *
 * Mail::build() makes an on-demand Mailer from a config ARRAY: nothing global is
 * touched, nothing is cached under a shared name, and the object is dropped
 * when the job ends. The platform mailer is returned untouched when the shop has
 * no override. Same per-shop decision as the configurator (override switch on +
 * a host), same encryption mapping, same From rule.
 */
final class CampaignMailer
{
    // === CONSTANTS ===
    private const TRANSPORT = 'smtp';

    /**
     * The mailer to send THIS shop's campaign mail through.
     *
     * Typed as the CONTRACT, not the concrete mailer: under Mail::fake() the
     * facade hands back a MailFake, which satisfies the contract and nothing
     * else — a concrete type here would make every campaign send explode in the
     * test suite and, worse, be caught by the job's own error handling.
     */
    public static function for(Shop $shop): Mailer
    {
        $settings = self::settingsFor($shop);

        if ($settings === null || ! $settings->override_env_smtp || ! $settings->smtp_host) {
            return Mail::mailer();
        }

        $config = [
            'transport' => self::TRANSPORT,
            'host' => (string) $settings->smtp_host,
            'port' => $settings->smtp_port ? (int) $settings->smtp_port : 587,
            'username' => $settings->smtp_username ?: null,
            'password' => $settings->smtp_password ?: null,
            'timeout' => null,
            'local_domain' => null,
        ];

        if ($settings->smtp_encryption) {
            $config['scheme'] = $settings->smtp_encryption === 'tls' ? 'smtp' : 'smtps';
        }

        $mailer = Mail::build($config);

        if ($settings->from_address) {
            $mailer->alwaysFrom((string) $settings->from_address, (string) ($settings->from_name ?: $shop->name));
        }

        return $mailer;
    }

    /** The From the mailable should stamp, or null for the platform default. */
    public static function fromFor(Shop $shop): ?array
    {
        $settings = self::settingsFor($shop);

        if ($settings === null || ! $settings->override_env_smtp || ! $settings->from_address) {
            return null;
        }

        return ['address' => (string) $settings->from_address, 'name' => (string) ($settings->from_name ?: $shop->name)];
    }

    /**
     * Keyed EXPLICITLY by the sending shop id via the audited cross-tenant query:
     * correct on a worker with no bound tenant, and never another shop's row.
     */
    private static function settingsFor(Shop $shop): ?MerchantMailSettings
    {
        return MerchantMailSettings::acrossAllTenants()
            ->where('shop_id', $shop->getKey())
            ->first();
    }
}
