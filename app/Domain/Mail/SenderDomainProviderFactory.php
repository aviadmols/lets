<?php

namespace App\Domain\Mail;

use App\Domain\Mail\Contracts\SenderDomainProvider;
use App\Domain\Mail\SendGrid\SendGridClient;
use App\Domain\Mail\Ses\SesClient;
use App\Models\PlatformMailSettings;

/**
 * provider name → the client that speaks it.
 *
 * The same shape as InvoiceProviderFactory and AiProviderFactory: the product
 * code above never learns a vendor's name, and a second vendor is a class and
 * a line here. That is what makes "we are leaving SendGrid" a settings change
 * rather than a rewrite.
 *
 * Tests swap the whole resolution with fake(), so no suite ever talks to a
 * real vendor.
 */
final class SenderDomainProviderFactory
{
    /** @var (callable(): SenderDomainProvider)|null */
    private static $fake = null;

    public static function current(): SenderDomainProvider
    {
        if (self::$fake !== null) {
            return (self::$fake)();
        }

        return PlatformMailSettings::current()->usesSes()
            ? new SesClient
            : new SendGridClient;
    }

    /** Is whichever provider the platform chose configured enough to ask? */
    public static function configured(): bool
    {
        return PlatformMailSettings::current()->usesSes()
            ? SesClient::configured()
            : SendGridClient::configured();
    }

    /** @param callable(): SenderDomainProvider $factory */
    public static function fake(callable $factory): void
    {
        self::$fake = $factory;
    }

    public static function clearFake(): void
    {
        self::$fake = null;
    }
}
