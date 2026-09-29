<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * The currency a storefront request may start a plan or a charge in.
 *
 * Prices are computed server-side in the store's currency, but the currency
 * CODE used to come straight from the request — and a plan's currency is sent
 * to PayPlus on every later token charge. A shopper who sent another code got
 * the same NUMBER charged in a different currency. So the code is accepted only
 * when it is one the platform takes (config payplus.accepted_currencies, which
 * defaults to the platform currency alone); anything else becomes the platform
 * currency, and the override is logged so a store that genuinely sells in
 * another currency is visible to the operator, who can add it to the list.
 */
final class StoreCurrency
{
    // === CONSTANTS ===
    private const FALLBACK = 'ILS';

    public static function platform(): string
    {
        $currency = strtoupper(trim((string) config('payplus.currency', self::FALLBACK)));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : self::FALLBACK;
    }

    /** @return list<string> */
    public static function accepted(): array
    {
        $list = array_values(array_filter(
            (array) config('payplus.accepted_currencies', []),
            static fn ($c): bool => is_string($c) && preg_match('/^[A-Z]{3}$/', $c) === 1,
        ));

        return array_values(array_unique([self::platform(), ...$list]));
    }

    /** The requested code when accepted, else the platform currency (logged when it differed). */
    public static function resolve(mixed $requested, ?int $shopId = null): string
    {
        $code = strtoupper(trim((string) $requested));

        if ($code === '') {
            return self::platform();
        }

        if (in_array($code, self::accepted(), true)) {
            return $code;
        }

        Log::warning('storefront.currency_overridden', [
            'shop_id' => $shopId,
            'requested' => mb_substr($code, 0, 8),
            'used' => self::platform(),
        ]);

        return self::platform();
    }
}
