<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Buys a new offline access token with the REFRESH token, no merchant present.
 *
 * The counterpart to ShopifyTokenExchange: that one needs a session token, so it
 * only ever runs while someone has the embedded admin open. Expiring offline
 * tokens lapse in about a day, and the scheduler bills on days nobody opens the
 * admin — so without this the app would be able to charge only while it is being
 * watched. The refresh token is good for months and is what covers the gap.
 *
 * Fail closed and NON-fatally: a failure returns false and leaves the old token
 * in place. The caller then makes its call and gets whatever Shopify says — a
 * refusal here must not turn a working request into an exception, and the next
 * embedded load re-mints from the session token anyway.
 */
final class ShopifyTokenRefresher
{
    // === CONSTANTS ===
    /** OAuth 2.0 refresh grant — the same token endpoint as every other grant. */
    private const GRANT_TYPE = 'refresh_token';
    /** HTTP timeout for the refresh call (seconds). */
    private const TIMEOUT_SECONDS = 30;

    /**
     * Make sure $shop holds a usable token before it is handed to a client.
     *
     * @return bool true when the shop now holds a fresh token (or never needed
     *              one), false when it is still stale and a merchant must open
     *              the app for token exchange to run
     */
    public function ensureFresh(Shop $shop): bool
    {
        if (! $shop->shopifyTokenNeedsRefresh()) {
            return true;
        }

        return $this->refresh($shop) !== null;
    }

    /**
     * Run the refresh grant and store the result. Returns the new token, or null
     * when the shop has no usable refresh token or Shopify refused.
     */
    public function refresh(Shop $shop): ?ShopifyToken
    {
        $refreshToken = $shop->shopifyRefreshToken();
        $shopDomain = (string) $shop->shopify_domain;

        if ($refreshToken === null || $shopDomain === '') {
            // A legacy install predates refresh tokens entirely. Nothing is wrong
            // with the shop — it simply cannot be revived from the background, and
            // the first embedded load will exchange a session token instead.
            Log::info('shopify.token_refresh.unavailable', [
                'shop_id' => $shop->getKey(),
                'reason' => $refreshToken === null ? 'no_refresh_token' : 'no_domain',
            ]);

            return null;
        }

        $app = ShopifyApps::credentials($shop->shopifyAppKey());
        if ($app['api_key'] === '' || $app['api_secret'] === '') {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->asForm()
                ->post(sprintf('https://%s/admin/oauth/access_token', $shopDomain), [
                    'client_id' => $app['api_key'],
                    'client_secret' => $app['api_secret'],
                    'grant_type' => self::GRANT_TYPE,
                    'refresh_token' => $refreshToken,
                ]);
        } catch (\Throwable $e) {
            Log::warning('shopify.token_refresh.error', [
                'shop_id' => $shop->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('shopify.token_refresh.failed', [
                'shop_id' => $shop->getKey(),
                'status' => $response->status(),
            ]);

            return null;
        }

        $token = ShopifyToken::fromResponse((array) $response->json());
        if ($token === null) {
            return null;
        }

        // A refresh answers with a NEW refresh token too; the old one is spent, so
        // storing the whole grant (not just the access token) is what makes the
        // next refresh possible.
        $shop->captureShopifyToken($token);

        Log::info('shopify.token_refresh.ok', [
            'shop_id' => $shop->getKey(),
            'expires_in' => $token->expiresIn,
        ]);

        return $token;
    }
}
