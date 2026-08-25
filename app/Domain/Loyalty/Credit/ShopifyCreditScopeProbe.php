<?php

namespace App\Domain\Loyalty\Credit;

use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Does this shop's Shopify grant actually include store credit?
 *
 * A shop installed before `write_store_credit_accounts` was added holds a
 * narrower grant, and redemption fails with a generic "not right now" the
 * shopper sees but the MERCHANT never does — the only trace is a log line.
 * This probe asks Shopify for the installed scopes so the admin screen can say
 * "reopen the app to re-authorize" out loud, before the first shopper hits it.
 *
 * Three-valued on purpose: true (granted), false (missing), null (could not
 * ask). Unknown is never treated as missing — a transport blip must not paint
 * a scary banner on a healthy shop.
 */
final class ShopifyCreditScopeProbe
{
    // === CONSTANTS ===
    /** The scope ShopifyStoreCreditIssuer's mutation needs. */
    public const SCOPE = 'write_store_credit_accounts';

    /** Scopes change only on re-auth, so a long cache costs nothing. */
    private const CACHE_TTL_HOURS = 6;

    private const CACHE_PREFIX = 'loyalty:scope-probe:';

    private const QUERY = <<<'GQL'
    { currentAppInstallation { accessScopes { handle } } }
    GQL;

    public function hasStoreCreditScope(Shop $shop): ?bool
    {
        if ($shop->platform !== Shop::PLATFORM_SHOPIFY || ! $shop->hasShopifyConnection()) {
            return null;
        }

        // A null probe result is not cached by remember(), so "unknown" is
        // simply asked again next time — exactly the retry we want.
        return Cache::remember(
            self::CACHE_PREFIX.$shop->getKey(),
            now()->addHours(self::CACHE_TTL_HOURS),
            fn (): ?bool => $this->probe($shop),
        );
    }

    private function probe(Shop $shop): ?bool
    {
        try {
            $body = ShopifyClientFactory::for($shop)->graphql(self::QUERY);
        } catch (\Throwable $e) {
            Log::info('loyalty.scope_probe_failed', [
                'shop_id' => $shop->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $scopes = data_get($body, 'data.currentAppInstallation.accessScopes');
        if (! is_array($scopes)) {
            return null; // an answer without scopes is no answer
        }

        foreach ($scopes as $scope) {
            if ((string) data_get($scope, 'handle') === self::SCOPE) {
                return true;
            }
        }

        return false;
    }
}
