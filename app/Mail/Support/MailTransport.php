<?php

namespace App\Mail\Support;

use App\Models\MerchantMailSettings;
use App\Models\PlatformMailSettings;
use App\Models\Shop;
use App\Models\ShopSenderDomain;

/**
 * WHICH relay one shop's mail leaves through, and WHAT From it carries.
 *
 * The one place that decision is made. It used to live twice — once in
 * MailSettingsConfigurator (Config::set, for request-time sends) and once in
 * CampaignMailer (Mail::build, for queue-time sends) — and two copies of a
 * routing rule is how a shop ends up sending through the right relay on one
 * path and the wrong one on the other.
 *
 * THE LADDER, most specific first:
 *
 *   1. THE MERCHANT'S OWN SMTP. They brought a relay; it is theirs, it wins,
 *      and nothing about the platform's arrangement should override a merchant
 *      who has taken responsibility for their own delivery.
 *   2. THE PLATFORM'S SENDGRID. One account — ours — for everyone else. The
 *      From is the shop's OWN verified domain when they have authenticated one
 *      (SenderDomains), so their customers see the shop's name and the shop's
 *      domain vouches for the mail; otherwise the platform's own address, which
 *      is the only other address this account is allowed to send as.
 *   3. NOTHING. The platform .env mailer, exactly as before this existed.
 *
 * AN UNVERIFIED DOMAIN IS NEVER A FROM. SendGrid would refuse the message, and
 * a message that somehow left unsigned would teach every receiving server that
 * this domain sends unauthenticated mail — the merchant's own deliverability,
 * spent on our mistake.
 */
final class MailTransport
{
    // === CONSTANTS ===
    private const TRANSPORT = 'smtp';

    /** SendGrid's relay takes the literal username "apikey"; the key is the password. */
    private const SENDGRID_USERNAME = 'apikey';

    /**
     * The mailer config array for this shop, or null for "the platform default
     * mailer, untouched".
     *
     * @return array{config: array<string, mixed>, from: ?array{address: string, name: string}}|null
     */
    public static function for(Shop $shop): ?array
    {
        $settings = self::settingsFor($shop);

        // 1. The merchant's own relay.
        if ($settings !== null && $settings->override_env_smtp && $settings->smtp_host) {
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

            return [
                'config' => $config,
                'from' => $settings->from_address
                    ? [
                        'address' => (string) $settings->from_address,
                        'name' => (string) ($settings->from_name ?: $shop->name),
                    ]
                    : null,
            ];
        }

        // 2. The platform's SendGrid account.
        $platform = PlatformMailSettings::current();

        if (! $platform->isConnected()) {
            return null;
        }

        return [
            'config' => [
                'transport' => self::TRANSPORT,
                'host' => (string) config('services.sendgrid.smtp_host'),
                'port' => (int) config('services.sendgrid.smtp_port', 587),
                'username' => (string) config('services.sendgrid.smtp_username', self::SENDGRID_USERNAME),
                'password' => (string) $platform->apiKey(),
                'scheme' => 'smtp',
                'timeout' => null,
                'local_domain' => null,
            ],
            'from' => self::sendGridFrom($shop, $settings, $platform),
        ];
    }

    /**
     * The From a SendGrid send carries: the shop's verified domain when it has
     * one, else the platform address.
     *
     * The local part follows the merchant's own from_address when they set one
     * (a merchant who writes "hello@" means hello@, on whichever domain is
     * theirs to send from), so the two settings compose instead of contradicting.
     *
     * @return array{address: string, name: string}|null
     */
    private static function sendGridFrom(
        Shop $shop,
        ?MerchantMailSettings $settings,
        PlatformMailSettings $platform,
    ): ?array {
        $name = (string) ($settings?->from_name ?: $shop->name);
        $domain = ShopSenderDomain::forShop((int) $shop->getKey());

        if ($domain !== null && $domain->isUsable()) {
            return [
                'address' => self::localPart($settings).'@'.$domain->sendingDomain(),
                'name' => $name,
            ];
        }

        $fallback = $platform->fromAddress();

        return $fallback !== null
            ? ['address' => $fallback, 'name' => (string) ($platform->fromName() ?: $name)]
            : null;
    }

    /** The bit before the @ the merchant asked for, or a safe default. */
    private static function localPart(?MerchantMailSettings $settings): string
    {
        $address = trim((string) ($settings?->from_address ?? ''));
        $at = strrpos($address, '@');

        if ($at === false || $at === 0) {
            return 'noreply';
        }

        $local = mb_substr($address, 0, $at);

        // Only the characters an address may safely carry; anything else means
        // a value we should not be building an envelope out of.
        return preg_match('/^[A-Za-z0-9._%+-]{1,64}$/', $local) === 1 ? $local : 'noreply';
    }

    /**
     * Keyed EXPLICITLY by the sending shop id via the audited cross-tenant
     * query: correct on a worker with no bound tenant, and never another shop's
     * credentials.
     */
    private static function settingsFor(Shop $shop): ?MerchantMailSettings
    {
        return MerchantMailSettings::acrossAllTenants()
            ->where('shop_id', $shop->getKey())
            ->first();
    }
}
