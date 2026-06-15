<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the platform HMAC on every Shopify webhook, BEFORE the controller.
 *
 * Non-negotiable rules (shopify-integration.md §1.3, §4):
 *   - Hash the RAW request body bytes (request->getContent()), never re-encoded
 *     JSON — framework re-encoding changes bytes and would 401 every webhook.
 *   - Timing-safe compare; absent/empty/mismatched HMAC ⇒ 401 (fail closed).
 *   - Empty SHOPIFY_WEBHOOK_SECRET in PRODUCTION ⇒ 503 (never silently accept).
 *
 * App-level webhooks are signed with the PLATFORM secret (the app secret), shared
 * by all shops — so this is one secret, not per-shop. (PayPlus callbacks are the
 * opposite and are verified by laravel-backend with a per-shop secret.)
 *
 * On success it stashes the verified raw body + parsed headers on the request so
 * the controller need not re-read or re-hash.
 */
final class VerifyShopifyWebhook
{
    // === CONSTANTS ===
    public const ATTR_VALID = 'shopify_webhook_valid';
    public const ATTR_RAW = 'shopify_webhook_raw';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('shopify.webhook_secret');

        // Fail closed: never accept unsigned payloads when misconfigured in prod.
        if ($secret === '') {
            if (app()->environment('production')) {
                Log::critical('shopify.webhook.secret_missing_in_production');

                return response()->json(['status' => 'webhook_secret_unconfigured'], Response::HTTP_SERVICE_UNAVAILABLE);
            }
            // Non-prod with no secret: still reject (fail closed), but 401 not 503.
            return response()->json(['status' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        $raw = $request->getContent(); // RAW bytes — must hash these, not json()
        $provided = (string) $request->header(config('shopify.webhook_headers.hmac', 'X-Shopify-Hmac-SHA256'), '');
        $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            Log::warning('shopify.webhook.invalid_hmac', [
                'topic' => $request->header(config('shopify.webhook_headers.topic', 'X-Shopify-Topic')),
                'shop_domain' => $request->header(config('shopify.webhook_headers.shop_domain', 'X-Shopify-Shop-Domain')),
            ]);

            return response()->json(['status' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set(self::ATTR_VALID, true);
        $request->attributes->set(self::ATTR_RAW, $raw);

        return $next($request);
    }
}
