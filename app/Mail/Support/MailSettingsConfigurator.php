<?php

namespace App\Mail\Support;

use App\Models\MerchantMailSettings;
use App\Models\Shop;
use Illuminate\Support\Facades\Config;

/**
 * Applies a SENDING shop's per-shop SMTP override into the live mail config for
 * the duration of a single send. When the shop has not opted into its own SMTP
 * (override_env_smtp = false) this is a no-op and the platform .env mailer is
 * used.
 *
 * Per-shop + runtime-scoped: the override is set just before a send and never
 * persisted into the shared config file, so two shops sending on the same worker
 * never cross From addresses or mailbox credentials. The SMTP password is read
 * through the model's `encrypted` cast (decrypted in-memory only here).
 *
 * Ported from the reference engine's mergeMailSettingsIntoConfig().
 */
final class MailSettingsConfigurator
{
    public static function apply(Shop $shop): void
    {
        // Keyed EXPLICITLY by the sending shop id via the audited cross-tenant
        // query: this reads exactly ONE shop's own settings row, correct even when
        // applied outside a bound-tenant context. It can never read another shop's
        // SMTP credentials (the where pins the id).
        $settings = MerchantMailSettings::acrossAllTenants()
            ->where('shop_id', $shop->getKey())
            ->first();

        if ($settings === null || ! $settings->override_env_smtp || ! $settings->smtp_host) {
            return; // platform .env mailer
        }

        Config::set('mail.mailers.smtp.host', $settings->smtp_host);

        if ($settings->smtp_port) {
            Config::set('mail.mailers.smtp.port', $settings->smtp_port);
        }
        if ($settings->smtp_encryption) {
            Config::set('mail.mailers.smtp.scheme', $settings->smtp_encryption === 'tls' ? 'smtp' : 'smtps');
        }
        if ($settings->smtp_username) {
            Config::set('mail.mailers.smtp.username', $settings->smtp_username);
        }
        if ($settings->smtp_password) {
            Config::set('mail.mailers.smtp.password', $settings->smtp_password);
        }
        if ($settings->from_address) {
            Config::set('mail.from.address', $settings->from_address);
            Config::set('mail.from.name', $settings->from_name ?: $shop->name);
        }

        // Force the SMTP mailer for this send (the platform default may be `log`).
        Config::set('mail.default', 'smtp');
    }
}
