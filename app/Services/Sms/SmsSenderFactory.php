<?php

namespace App\Services\Sms;

use App\Models\MerchantSmsSettings;
use App\Models\Shop;
use App\Support\Tenant;

/**
 * Builds an SMS sender bound to ONE shop's decrypted credentials — the SMS sibling
 * of InvoiceProviderFactory::for() and PayPlusGatewayFactory::for(), following the
 * same tenancy law: credentials are decrypted once and injected as constructor
 * state, never read at call time, and an instance is never reused across shops.
 *
 * Returns NULL — never a silent no-op sender — when the shop has not configured
 * SMS. Every call site is therefore a clean no-op for a merchant who never opted
 * in, and no caller can mistake "not configured" for "delivered".
 */
final class SmsSenderFactory
{
    /**
     * Test-only override. Lets a test assert what would have been sent without
     * real HTTP. Null in production.
     *
     * @var (callable(Shop): ?SmsSender)|null
     */
    private static $fakeResolver = null;

    /** The sender for a shop that has SMS enabled AND fully configured, else null. */
    public static function for(Shop $shop): ?SmsSender
    {
        $settings = self::settingsFor($shop);

        // The merchant's own switch is checked BEFORE the test hook, deliberately:
        // a fake that short-circuits first would bypass the gate that keeps a shop
        // which never opted in from spending money on messages — and no test could
        // then prove the gate works.
        if ($settings === null || ! $settings->usable()) {
            return null;
        }

        if (self::$fakeResolver !== null) {
            return (self::$fakeResolver)($shop);
        }

        return match ($settings->providerKey()) {
            MerchantSmsSettings::PROVIDER_019 => new Sms019Sender(
                username: (string) $settings->accountName(),
                token: (string) $settings->apiToken(),
                sender: (string) $settings->senderName(),
            ),
            default => null,
        };
    }

    /** @param (callable(Shop): ?SmsSender)|null $resolver */
    public static function fake(?callable $resolver): void
    {
        self::$fakeResolver = $resolver;
    }

    private static function settingsFor(Shop $shop): ?MerchantSmsSettings
    {
        return Tenant::run($shop, static fn (): MerchantSmsSettings => MerchantSmsSettings::current());
    }
}
