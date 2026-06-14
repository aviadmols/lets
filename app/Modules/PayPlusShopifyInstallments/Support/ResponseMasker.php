<?php

namespace App\Modules\PayPlusShopifyInstallments\Support;

/**
 * Recursively masks sensitive keys out of a PayPlus response before it touches
 * the ledger (raw_response_masked). Ported from the reference engine's masking:
 * card numbers, tokens, secrets, and auth material never land in our DB readable.
 * The transaction uid / approval number are kept (they are operational, not PAN).
 */
final class ResponseMasker
{
    // === CONSTANTS ===
    /** Keys whose values are replaced with a fixed mask, case-insensitively. */
    private const SENSITIVE_KEYS = [
        'card_number', 'credit_card_number', 'cc_number', 'pan',
        'cvv', 'cvv2', 'card_cvv',
        'secret_key', 'api_key', 'password', 'token',
        'authorization', 'auth',
    ];

    private const MASK = '***';

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function mask(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::mask($value);
                continue;
            }

            $out[$key] = self::isSensitive((string) $key) ? self::MASK : $value;
        }

        return $out;
    }

    private static function isSensitive(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($needle, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
