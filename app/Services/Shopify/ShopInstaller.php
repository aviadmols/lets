<?php

namespace App\Services\Shopify;

use App\Jobs\Products\ImportShopProductsJob;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * The single, idempotent "an offline token just arrived for this shop — finish the
 * install" routine. ONE place owns the post-token install steps so BOTH paths that
 * can produce an offline token converge here, never duplicating the logic:
 *
 *   1. Legacy redirect OAuth (OAuthController::callback) — code → token exchange.
 *   2. Managed install / token exchange (EmbeddedAuthenticate) — session-token →
 *      offline-token exchange on the first embedded load.
 *
 * Steps (extracted verbatim from the original callback 5–8):
 *   - upsert the Shop by shopify_domain (reinstall reuses the row, never dupes);
 *   - capture (encrypt) the offline token + granted scopes onto that row;
 *   - provision/link the shop-scoped admin login (idempotent on reinstall);
 *   - register this shop's webhooks (tenant-bound job);
 *   - backfill this shop's product cache (tenant-bound job).
 *
 * Tenant-safety: the shop domain is supplied by the CALLER, which must have already
 * proven it (OAuth HMAC+state, or a verified session-token dest claim). This service
 * never derives the shop from request/global state — it only persists what it is given.
 */
final class ShopInstaller
{
    public function __construct(private readonly MerchantUserProvisioner $provisioner) {}

    /**
     * Create-or-refresh the Shop for a freshly minted offline token and run the
     * install side-effects. Idempotent: a second call for the same domain reuses the
     * existing row + user and re-runs the (idempotent) sync jobs.
     *
     * @param  string       $shopDomain  a validated *.myshopify.com domain (caller-proven)
     * @param  string       $accessToken the offline access token to store (encrypted)
     * @param  string|null  $scopes      the granted scope string, or null
     */
    public function installFromToken(string $shopDomain, string $accessToken, ?string $scopes): Shop
    {
        $newInstall = ! Shop::query()->where('shopify_domain', $shopDomain)->exists();

        // Upsert the Shop (matched by domain ⇒ reinstall reuses the row) and store
        // the ENCRYPTED offline token + granted scopes.
        $shop = Shop::query()->firstOrCreate(
            ['shopify_domain' => $shopDomain],
            ['name' => $shopDomain, 'platform' => Shop::PLATFORM_SHOPIFY, 'status' => Shop::STATUS_INSTALLED],
        );
        $shop->captureShopifyInstall($accessToken, ($scopes !== null && $scopes !== '') ? $scopes : null);

        // Provision/link an admin login BOUND to this shop, so the merchant gets a
        // store-scoped login. Idempotent on reinstall (reuses the existing user).
        $this->provisioner->provisionFor($shop);

        // Register webhooks for THIS shop (idempotent, tenant-bound job).
        RegisterShopifyWebhooksJob::dispatch($shop->id);

        // Backfill the local product cache from this shop's source (idempotent,
        // tenant-bound, on the `sync` queue). Product webhooks keep it fresh after.
        ImportShopProductsJob::dispatch($shop->id);

        Log::info('shopify.install.completed', [
            'shop' => $shopDomain,
            'new_install' => $newInstall,
        ]);

        return $shop;
    }
}
