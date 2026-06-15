<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Models\Shop;
use App\Services\Shopify\ShopifyDomain;
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

    /** GET /shopify/install?shop={shop}.myshopify.com */
    public function install(Request $request): RedirectResponse
    {
        $shop = ShopifyDomain::normalize((string) $request->query('shop', ''));
        if ($shop === '') {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('Missing or invalid "shop" parameter (expected *.myshopify.com).'));
        }

        $clientId = (string) config('shopify.api_key');
        if ($clientId === '') {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'SHOPIFY_API_KEY is not configured.');
        }

        // Single-use state nonce, cached by shop (consumed once in callback).
        $nonce = bin2hex(random_bytes(16));
        Cache::put(self::STATE_CACHE_PREFIX.$shop, $nonce, self::STATE_TTL_SECONDS);

        $authorizeUrl = sprintf('https://%s/admin/oauth/authorize?%s', $shop, http_build_query([
            'client_id' => $clientId,
            'scope' => (string) config('shopify.oauth_scopes'),
            'redirect_uri' => route('shopify.callback', [], true),
            'state' => $nonce,
            // grant_options[] omitted entirely ⇒ OFFLINE (long-lived) token.
        ], '', '&', PHP_QUERY_RFC3986));

        return redirect()->away($authorizeUrl);
    }

    /** GET /shopify/callback?code=&hmac=&shop=&state=&timestamp= */
    public function callback(Request $request): RedirectResponse
    {
        $secret = (string) config('shopify.api_secret');
        if ($secret === '') {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'SHOPIFY_API_SECRET is not configured.');
        }

        // 1. HMAC of the whole query string (fail closed).
        if (! ShopifyDomain::verifyQueryHmac($request->query(), $secret)) {
            Log::warning('shopify.oauth.invalid_hmac', ['shop' => $request->query('shop')]);
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid HMAC signature.');
        }

        // 2. shop param re-validated (never trust it before/again).
        $shop = ShopifyDomain::normalize((string) $request->query('shop', ''));
        if ($shop === '') {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid shop in callback.');
        }

        // 3. state consumed exactly once.
        $cachedNonce = Cache::pull(self::STATE_CACHE_PREFIX.$shop);
        $returnedState = (string) $request->query('state', '');
        if (! is_string($cachedNonce) || $cachedNonce === '' || ! hash_equals($cachedNonce, $returnedState)) {
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid or expired OAuth state.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Missing authorization code.');
        }

        // 4. Exchange code → offline access token.
        $response = Http::timeout(self::TOKEN_EXCHANGE_TIMEOUT)
            ->acceptJson()
            ->asForm()
            ->post(sprintf('https://%s/admin/oauth/access_token', $shop), [
                'client_id' => config('shopify.api_key'),
                'client_secret' => $secret,
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

        // 5. Upsert the Shop (matched by domain ⇒ reinstall reuses the row) and
        //    store the ENCRYPTED offline token + granted scopes.
        $newInstall = ! Shop::query()->where('shopify_domain', $shop)->exists();
        $shopModel = Shop::query()->firstOrCreate(
            ['shopify_domain' => $shop],
            ['name' => $shop, 'status' => Shop::STATUS_INSTALLED],
        );
        $shopModel->captureShopifyInstall($accessToken, $scopes !== '' ? $scopes : null);

        // 6. Register webhooks for THIS shop (idempotent, tenant-bound job).
        RegisterShopifyWebhooksJob::dispatch($shopModel->id);

        // 7. BackfillShopCatalogJob — products/collections for trigger matching.
        // TODO(sync phase): BackfillShopCatalogJob::dispatch($shopModel->id) once
        //   the sync surface lands; not in this run's scope.

        // 8. Handoff to saas-multitenancy-billing: trial/subscribe confirmation.
        // TODO(saas agent): redirect into the AppSubscription trial flow here
        //   (e.g. route('billing.confirm', ['shop' => $shop])). For v1 baseline we
        //   go straight to the embedded admin; the SaaS agent owns the gate.
        Log::info('shopify.oauth.installed', ['shop' => $shop, 'new_install' => $newInstall]);

        // 9. Final redirect → embedded admin home.
        $handle = (string) config('shopify.app_handle');

        return redirect()->away(sprintf('https://%s/admin/apps/%s', $shop, $handle));
    }
}
