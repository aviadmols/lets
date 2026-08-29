<?php

namespace App\Domain\Ai\Providers;

use App\Models\PlatformAiSettings;

/**
 * provider name → implementation. One entry today; adding a vendor is a class
 * and a line here — the product code above the gateway never learns a name.
 *
 * Tests swap the whole resolution with fake(), the InvoiceProviderFactory
 * idiom, so no suite ever talks to a real vendor.
 */
final class AiProviderFactory
{
    /** @var (callable(): AiProvider)|null */
    private static $fake = null;

    public static function current(): AiProvider
    {
        if (self::$fake !== null) {
            return (self::$fake)();
        }

        return match (PlatformAiSettings::current()->provider) {
            default => new AnthropicProvider,
        };
    }

    /** @param callable(): AiProvider $factory */
    public static function fake(callable $factory): void
    {
        self::$fake = $factory;
    }

    public static function clearFake(): void
    {
        self::$fake = null;
    }
}
