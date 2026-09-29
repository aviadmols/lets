<?php

namespace App\Support\Ui;

/**
 * Where a merchant reaches LETS for help. There is no chat or help centre yet,
 * so it is the address the privacy policy already publishes as the contact:
 * the platform's sending address, or the support mailbox when none is set.
 * One place, so the top bar's help button and the policy cannot disagree.
 */
final class SupportContact
{
    // === CONSTANTS ===
    public const FALLBACK_EMAIL = 'support@lets.co.il';

    public static function email(): string
    {
        return (string) (config('mail.from.address') ?: self::FALLBACK_EMAIL);
    }

    public static function mailto(): string
    {
        return 'mailto:'.self::email();
    }
}
