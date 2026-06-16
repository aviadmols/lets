<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\Shopify\ShopifyDomain;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The storefront/extension trust boundary. Storefront JS and checkout/post-purchase
 * extensions CANNOT be trusted to send a shop id — they route through a Shopify
 * App Proxy (https://{shop}/apps/payplus/... → https://app.lets.co.il/proxy/...),
 * and Shopify appends a `signature` query param (HMAC of the sorted params with the
 * app shared secret). THIS is the proof; the `shop` param is then trusted only
 * BECAUSE it is inside the signed set.
 *
 * Non-negotiable (shopify-integration.md §8, §10):
 *   - Fail closed: empty platform secret in production ⇒ 503; bad/absent
 *     signature ⇒ 401. Never derive shop from an unsigned param.
 *   - The verified `shop` param (a *.myshopify.com domain) resolves the Shop row.
 *   - Bind the Tenant from THAT shop for the request, clear it after (workers /
 *     the request container must never leak one shop's context into another).
 *
 * On success it stashes the resolved Shop on the request so controllers need not
 * re-resolve it.
 */
final class VerifyShopifyAppProxy
{
    // === CONSTANTS ===
    public const ATTR_SHOP = 'shopify_proxy_shop';

    public function handle(Request $request, Closure $next): Response
    {
        // The proxy signature is signed with the app secret (same secret family as
        // webhooks/OAuth); webhook_secret falls back to api_secret in config.
        $secret = (string) config('shopify.webhook_secret');

        if ($secret === '') {
            if (app()->environment('production')) {
                Log::critical('shopify.proxy.secret_missing_in_production');

                return response()->json(['status' => 'proxy_secret_unconfigured'], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            return response()->json(['status' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        if (! ShopifyDomain::verifyProxySignature($request->query(), $secret)) {
            Log::warning('shopify.proxy.invalid_signature', ['shop' => $request->query('shop')]);

            return response()->json(['status' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        // The shop param is trusted ONLY because it is inside the signed set; still
        // validate the domain shape before any DB lookup (defence in depth).
        $domain = ShopifyDomain::normalize((string) $request->query('shop', ''));
        if ($domain === '') {
            return response()->json(['status' => 'invalid_shop'], Response::HTTP_UNAUTHORIZED);
        }

        $shop = Shop::query()->where('shopify_domain', $domain)->first();
        if ($shop === null) {
            // Signed by Shopify but we have no install row (never installed / fully
            // purged). Nothing to serve; 404 rather than leak which shops exist.
            return response()->json(['status' => 'unknown_shop'], Response::HTTP_NOT_FOUND);
        }

        $request->attributes->set(self::ATTR_SHOP, $shop);

        // Bind the tenant for the request lifetime; clear after (request scope).
        return Tenant::run($shop, fn (): Response => $next($request));
    }
}
