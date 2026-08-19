<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Controllers\WooCommerce\Storefront\WooStorefrontController;
use App\Http\Middleware\SetAdminLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Open LETS inside wp-admin" — step 1 of 2.
 *
 * The WordPress plugin's SERVER asks (over the per-shop HMAC group) for a URL it
 * can drop into an iframe; we mint a single-use, 60-second token and hand back
 * the redeem URL. Step 2 is EmbedLoginController.
 *
 * THE TENANT IS THE SIGNATURE'S SHOP, NEVER THE BODY. The plugin tells us WHO is
 * looking (the WordPress user's email/name) but never WHICH shop — a body-supplied
 * shop_id is ignored outright, so a merchant who edits their own plugin can only
 * ever mint a token for their own store. That is the whole security model of this
 * endpoint, and it is why the token payload is built from $shop, not $request.
 *
 * The token is opaque and short-lived on purpose: it travels in a URL (referrers,
 * logs, browser history), so it is worth exactly one redemption within one minute.
 */
final class EmbedSessionController extends WooStorefrontController
{
    // === CONSTANTS ===
    /** Cache key namespace. Redeemed with Cache::pull → single use. */
    public const CACHE_PREFIX = 'embed:woocommerce:';

    /** Seconds the minted URL stays redeemable (also echoed as expires_in). */
    public const TTL_SECONDS = 60;

    /** 48 random chars — comfortably past the 40 the contract asks for. */
    public const TOKEN_LENGTH = 48;

    private const MAX_NAME = 120;

    private const MAX_EMAIL = 190;

    private const MAX_URL = 500;

    /** POST /api/woocommerce/embed/session */
    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $token = Str::random(self::TOKEN_LENGTH);

        Cache::put(self::CACHE_PREFIX.$token, [
            // The HMAC-verified shop. Never $request->input('shop_id').
            'shop_id' => (int) $shop->getKey(),
            'email' => $this->cleanEmail($request->input('wp_user_email')),
            'name' => $this->boundedText($request->input('wp_user_name'), self::MAX_NAME),
            'wp_user_id' => (int) $request->input('wp_user_id', 0),
            'locale' => $this->locale($request->input('locale')),
            'return_url' => $this->returnUrl($request->input('return_url')),
        ], self::TTL_SECONDS);

        return response()->json([
            'url' => route('woocommerce.embed.login', ['token' => $token]),
            'expires_in' => self::TTL_SECONDS,
        ]);
    }

    /** en/he only; anything else is the panel default rather than a broken locale. */
    private function locale(mixed $value): string
    {
        $locale = is_string($value) ? trim($value) : '';

        return in_array($locale, SetAdminLocale::SUPPORTED, true) ? $locale : SetAdminLocale::DEFAULT;
    }

    /**
     * Where "back to WordPress" points. http is allowed (a staging WP site is
     * routinely plain http) but the scheme is ALLOW-LISTED: this string ends up
     * in a link, and `javascript:` in a merchant-supplied return URL would be a
     * script running in the admin's own origin.
     */
    private function returnUrl(mixed $value): ?string
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
        ) {
            return null;
        }

        return mb_substr($url, 0, self::MAX_URL);
    }

    private function boundedText(mixed $value, int $limit): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text !== '' ? mb_substr($text, 0, $limit) : null;
    }

    /** An address we would store on a User row, or nothing. */
    protected function cleanEmail(mixed $value): ?string
    {
        $email = parent::cleanEmail($value);

        return $email !== null ? mb_substr($email, 0, self::MAX_EMAIL) : null;
    }
}
