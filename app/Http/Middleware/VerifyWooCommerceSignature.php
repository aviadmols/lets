<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a WooCommerce plugin → SaaS request via the per-shop API-key HMAC
 * (the WooCommerce analogue of VerifyShopifyAppProxy). The plugin holds a connection
 * token {api_key, api_secret} and signs every server call:
 *
 *     X-LETS-Key        = api_key
 *     X-LETS-Timestamp  = unix seconds
 *     X-LETS-Signature  = base64( HMAC-SHA256( ts + METHOD + path + rawBody, api_secret ) )
 *
 * We look the shop up by sha256(api_key) == lets_api_key_hash (the plaintext key is
 * never stored), recompute the HMAC with the shop's DECRYPTED lets_api_secret, and
 * constant-time compare. Stale timestamps are rejected (replay window). Fails CLOSED
 * with 401 — never reveals which check failed beyond a coarse reason. On success the
 * verified shop is stashed + bound as tenant for the request.
 *
 * Tenant-safety: the shop is derived SOLELY from the verified key hash + signature; a
 * forged or stale request can never bind a shop.
 */
final class VerifyWooCommerceSignature
{
    // === CONSTANTS ===
    public const ATTR_SHOP = 'lets_wc_shop';

    private const MAX_SKEW_SECONDS = 300;
    private const HEADER_KEY = 'X-LETS-Key';
    private const HEADER_TIMESTAMP = 'X-LETS-Timestamp';
    private const HEADER_SIGNATURE = 'X-LETS-Signature';

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header(self::HEADER_KEY, '');
        $ts = (string) $request->header(self::HEADER_TIMESTAMP, '');
        $sig = (string) $request->header(self::HEADER_SIGNATURE, '');

        if ($key === '' || $ts === '' || $sig === '') {
            return $this->deny('missing_credentials');
        }

        if (! ctype_digit($ts) || abs(time() - (int) $ts) > self::MAX_SKEW_SECONDS) {
            return $this->deny('stale_timestamp');
        }

        $shop = Shop::query()->where('lets_api_key_hash', hash('sha256', $key))->first();
        $secret = $shop?->lets_api_secret;
        if ($shop === null || $secret === null || $secret === '') {
            return $this->deny('unknown_key');
        }

        $expected = base64_encode(hash_hmac(
            'sha256',
            $ts.$request->getMethod().$request->getPathInfo().$request->getContent(),
            (string) $secret,
            true,
        ));

        if (! hash_equals($expected, $sig)) {
            return $this->deny('bad_signature');
        }

        $request->attributes->set(self::ATTR_SHOP, $shop);
        Tenant::set($shop);

        try {
            return $next($request);
        } finally {
            Tenant::clear();
        }
    }

    private function deny(string $reason): Response
    {
        return response()->json(['error' => 'unauthorized', 'reason' => $reason], 401);
    }
}
