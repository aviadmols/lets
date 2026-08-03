<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Models\MerchantLoyaltySettings;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/loyalty/page-url
 *
 * The WooCommerce half of "who is this shopper". WooCommerce has no App Proxy to
 * sign requests for us, so the PLUGIN'S SERVER (which already holds the shared
 * secret) tells us who is logged in over HMAC, and we hand back a short-lived
 * Laravel signed URL for exactly that person. The plugin renders it in an
 * iframe; the browser never sees the secret and cannot edit the identity — any
 * tampering breaks the signature.
 *
 * The customer reference we sign is the plugin's own convention (WC user id, or
 * the billing email for a guest), the same one WooGatewayFinalizer records on
 * the ledger — so the member the page shows is the member who earns the points.
 */
final class WooLoyaltyPageUrlController extends WooStorefrontController
{
    // === CONSTANTS ===
    /** How long the minted link stays valid. Long enough to read, short enough to leak safely. */
    private const TTL_MINUTES = 60;

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return Tenant::run($shop, function () use ($request, $shop): JsonResponse {
            if (! MerchantLoyaltySettings::current()->enabled) {
                // Not an error: the merchant simply has not turned the club on.
                return response()->json(['ok' => true, 'enabled' => false, 'url' => null]);
            }

            $customerRef = (string) ($this->cleanString($request->input('customer_ref')) ?? '');
            if ($customerRef === '') {
                // A logged-out visitor: the plugin shows its own sign-in prompt
                // rather than a members page that could belong to anyone.
                return response()->json(['ok' => true, 'enabled' => true, 'url' => null, 'reason' => 'guest']);
            }

            $url = URL::temporarySignedRoute('loyalty.signed.page', now()->addMinutes(self::TTL_MINUTES), [
                'shop' => (int) $shop->getKey(),
                'ref' => $customerRef,
                'email' => (string) ($this->cleanEmail($request->input('email')) ?? ''),
                'name' => (string) ($this->cleanString($request->input('name')) ?? ''),
            ]);

            return response()->json(['ok' => true, 'enabled' => true, 'url' => $url]);
        });
    }
}
