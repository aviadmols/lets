<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed WooCommerce plugin call that CHANGES something is honoured once.
 *
 * VerifyWooCommerceSignature accepts any correctly signed request inside its
 * ±300 s window, so a captured one (start a plan, accept an upsell, verify a
 * code, refund) could be sent again, unchanged, for five minutes. This remembers
 * each accepted signature for longer than that window and refuses a second
 * arrival. Runs AFTER VerifyWooCommerceSignature, so only genuine signatures
 * ever reach the cache and a forger cannot fill it.
 *
 * Applied per route, to the state-changing ones only. The read-ish calls (a
 * quote, a settings read, the account bootstrap) are left alone on purpose: two
 * identical ones in the same second are a normal page load, and replaying a read
 * gains nothing. The key is scoped by shop, so shops can never collide.
 */
final class RejectReplayedWooSignature
{
    // === CONSTANTS ===
    private const CACHE_PREFIX = 'lets:wc-sig-seen:';

    /** Longer than the signature's whole ±300 s acceptance window. */
    public const REMEMBER_SECONDS = 660;

    private const HEADER_SIGNATURE = 'X-LETS-Signature';

    public function handle(Request $request, Closure $next): Response
    {
        $shop = $request->attributes->get(VerifyWooCommerceSignature::ATTR_SHOP);
        $signature = (string) $request->header(self::HEADER_SIGNATURE, '');

        if (! $shop instanceof Shop || $signature === '') {
            // Never reached behind VerifyWooCommerceSignature; refuse rather than guess.
            return response()->json(['error' => 'unauthorized', 'reason' => 'unverified'], Response::HTTP_UNAUTHORIZED);
        }

        $key = self::CACHE_PREFIX.(int) $shop->getKey().':'.hash('sha256', $signature);

        // add() is atomic: exactly one arrival of a signature wins it.
        if (! Cache::add($key, 1, self::REMEMBER_SECONDS)) {
            Log::warning('woocommerce.signature_replayed', [
                'shop_id' => (int) $shop->getKey(),
                'path' => $request->getPathInfo(),
            ]);

            return response()->json(['error' => 'unauthorized', 'reason' => 'replayed'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
