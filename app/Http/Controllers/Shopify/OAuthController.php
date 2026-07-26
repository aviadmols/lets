<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Services\Shopify\ShopifyApps;
use App\Services\Shopify\ShopifyDomain;
use App\Services\Shopify\ShopInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public-app OAuth (authorization-code grant). The reference engine had only a
 * single-tenant variant that persisted ONE token to a global settings singleton;
 * this version upserts a per-shop, ENCRYPTED, OFFLINE token onto the matching
 * `shops` row (matched by shopify_domain — reinstall never duplicates the Shop).
 *
 * Flow (see shopify-integration.md §3):
 *   GET /shopify/install?shop=…  → redirect to Shopify authorize (state nonce)
 *   GET /shopify/callback        → verify HMAC + state, exchange code→token,
 *                                  upsert Shop, register webhooks, redirect into
 *                                  the embedded admin.
 *
 * Token kind: OFFLINE (long-lived) — background billing/sync run with no user
 * present. Online/session tokens authenticate only the embedded-admin REQUEST
 * (see SessionTokenAuth middleware).
 */
final class OAuthController extends Controller
{
    // === CONSTANTS ===
    private const STATE_CACHE_PREFIX = 'shopify:oauth_state:';
    private const STATE_TTL_SECONDS = 300; // 5 minutes
    private const TOKEN_EXCHANGE_TIMEOUT = 30;

    /**
     * GET /shopify/install?shop={shop}.myshopify.com[&app=custom]
     *
     * `app` picks which Partner app to install through (default: the public
     * App-Store app). Stage-1 test stores use the CUSTOM app's install link, so
     * the same deployment onboards them without touching the public listing.
     */
    public function install(Request $request): RedirectResponse
    {
        $shop = ShopifyDomain::normalize((string) $request->query('shop', ''));
        if ($shop === '') {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('Missing or invalid "shop" parameter (expected *.myshopify.com).'));
        }

        $appKey = ShopifyApps::normalize((string) $request->query('app', ''));
        $app = ShopifyApps::credentials($appKey);
        if ($app['api_key'] === '') {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'SHOPIFY_API_KEY is not configured.');
        }

        // Single-use state nonce, cached by shop (consumed once in callback). The
        // chosen app rides along so the callback exchanges with the SAME identity.
        $nonce = bin2hex(random_bytes(16));
        Cache::put(self::STATE_CACHE_PREFIX.$shop, ['nonce' => $nonce, 'app' => $appKey], self::STATE_TTL_SECONDS);

        $authorizeUrl = sprintf('https://%s/admin/oauth/authorize?%s', $shop, http_build_query([
            'client_id' => $app['api_key'],
            'scope' => $app['oauth_scopes'],
            'redirect_uri' => route('shopify.callback', [], true),
            'state' => $nonce,
            // grant_options[] omitted entirely ⇒ OFFLINE (long-lived) token.
        ], '', '&', PHP_QUERY_RFC3986));

        return redirect()->away($authorizeUrl);
    }

    /** GET /shopify/callback?code=&hmac=&shop=&state=&timestamp= */
    public function callback(Request $request): RedirectResponse
    {
        if (ShopifyApps::secrets() === []) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'SHOPIFY_API_SECRET is not configured.');
        }

        // 1. HMAC of the whole query string (fail closed). The matching secret
        //    also IDENTIFIES which Partner app this install came through.
        $appKey = ShopifyApps::matchQueryHmac($request->query());
        if ($appKey === null) {
            Log::warning('shopify.oauth.invalid_hmac', ['shop' => $request->query('shop')]);
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid HMAC signature.');
        }
        $app = ShopifyApps::credentials($appKey);

        // 2. shop param re-validated (never trust it before/again).
        $shop = ShopifyDomain::normalize((string) $request->query('shop', ''));
        if ($shop === '') {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid shop in callback.');
        }

        // 3. state consumed exactly once — and it must have been minted for the
        //    SAME app whose secret just verified the HMAC.
        $cached = Cache::pull(self::STATE_CACHE_PREFIX.$shop);
        $cachedNonce = is_array($cached) ? (string) ($cached['nonce'] ?? '') : '';
        $cachedApp = is_array($cached) ? (string) ($cached['app'] ?? '') : '';
        $returnedState = (string) $request->query('state', '');
        if ($cachedNonce === '' || ! hash_equals($cachedNonce, $returnedState) || $cachedApp !== $appKey) {
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid or expired OAuth state.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Missing authorization code.');
        }

        // 4. Exchange code → offline access token (with the identified app's creds).
        $response = Http::timeout(self::TOKEN_EXCHANGE_TIMEOUT)
            ->acceptJson()
            ->asForm()
            ->post(sprintf('https://%s/admin/oauth/access_token', $shop), [
                'client_id' => $app['api_key'],
                'client_secret' => $app['api_secret'],
                'code' => $code,
            ]);

        if (! $response->successful()) {
            Log::error('shopify.oauth.token_exchange_failed', ['shop' => $shop, 'status' => $response->status()]);
            abort(Response::HTTP_BAD_GATEWAY, 'Failed to exchange code for an access token.');
        }

        $accessToken = (string) ($response->json('access_token') ?? '');
        $scopes = (string) ($response->json('scope') ?? '');
        if ($accessToken === '') {
            abort(Response::HTTP_BAD_GATEWAY, 'Access token missing in Shopify response.');
        }

        // 5–7. Upsert the Shop (matched by domain ⇒ reinstall reuses the row),
        //   store the ENCRYPTED offline token + granted scopes, provision the
        //   store-scoped admin login, and register webhooks + backfill products.
        //   This shared routine is ALSO used by the managed-install / token-exchange
        //   path (EmbeddedAuthenticate) — one install routine, never duplicated.
        app(ShopInstaller::class)->installFromToken($shop, $accessToken, $scopes !== '' ? $scopes : null, $appKey);

        // 8. Handoff to saas-multitenancy-billing: trial/subscribe confirmation.
        // TODO(saas agent): redirect into the AppSubscription trial flow here
        //   (e.g. route('billing.confirm', ['shop' => $shop])). For v1 baseline we
        //   go straight to the embedded admin; the SaaS agent owns the gate.
        Log::info('shopify.oauth.installed', ['shop' => $shop, 'app' => $appKey]);

        // 9. Final redirect → embedded admin home (the INSTALLING app's handle).
        return redirect()->away(sprintf('https://%s/admin/apps/%s', $shop, $app['handle']));
    }
}
