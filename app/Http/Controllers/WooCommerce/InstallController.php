<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Middleware\VerifyWooCommerceSignature;
use App\Jobs\Products\ImportShopProductsJob;
use App\Models\Shop;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Services\WooCommerce\WooStoreUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The WooCommerce plugin connect handshake (completes the admin onboarding loop). The
 * request is already HMAC-verified + the shop bound by VerifyWooCommerceSignature; the
 * shop is derived ONLY from the verified key. This:
 *   1. verifies the plugin's reported site URL is a plain https URL ON the admin-entered
 *      woocommerce_domain (a token can only connect the store it was minted for; a shop
 *      with no domain cannot connect, and an unparseable URL is refused, never skipped),
 *   2. stores the connection (base_url + a per-shop wc_webhook_secret, plus the WC REST
 *      consumer key/secret when the plugin supplies them) in the encrypted bag,
 *   3. mints a wc_shop_token (the opaque segment future WC webhooks are delivered to),
 *   4. returns the wc_webhook_secret so the plugin can verify SaaS→plugin callbacks.
 */
final class InstallController
{
    // === CONSTANTS ===
    /** Machine-readable refusals the plugin shows its admin. */
    public const ERROR_NO_DOMAIN = 'no_store_domain';

    public const ERROR_DOMAIN_MISMATCH = 'domain_mismatch';

    public const ERROR_INVALID_BASE_URL = 'invalid_base_url';

    public function install(Request $request): JsonResponse
    {
        $shop = $this->shop($request);

        // Domain binding. A token can only connect the store it was minted for,
        // so a shop with no minted-for domain cannot connect at all — there is
        // nothing to bind the reported URL to.
        $expected = (string) ($shop->woocommerce_domain ?? '');
        if ($expected === '') {
            return response()->json(['error' => self::ERROR_NO_DOMAIN], 422);
        }

        // The reported URL is merchant input that we later render into links and
        // connect to: it must be a plain https URL on the minted-for domain, and a
        // value that cannot even be parsed is REFUSED, never skipped.
        $creds = $shop->woocommerce_credentials ?: [];
        $reportedRaw = trim((string) $request->input('base_url', ''));
        if ($reportedRaw !== '') {
            $reported = app(WooCommerceShopProvisioner::class)->normalizeDomain($reportedRaw);
            if ($reported !== '' && $reported !== $expected) {
                return response()->json(['error' => self::ERROR_DOMAIN_MISMATCH, 'expected' => $expected], 422);
            }

            $baseUrl = WooStoreUrl::forInstall($reportedRaw, $expected);
            if ($baseUrl === null) {
                return response()->json(['error' => self::ERROR_INVALID_BASE_URL, 'expected' => $expected], 422);
            }
        } else {
            $baseUrl = WooStoreUrl::forInstall((string) ($creds['base_url'] ?? ''), $expected) ?? 'https://'.$expected;
        }

        $creds['base_url'] = $baseUrl;
        $creds['wc_webhook_secret'] = (string) ($creds['wc_webhook_secret'] ?? Str::random(48));
        if ($request->filled('consumer_key')) {
            $creds['consumer_key'] = (string) $request->input('consumer_key');
        }
        if ($request->filled('consumer_secret')) {
            $creds['consumer_secret'] = (string) $request->input('consumer_secret');
        }

        $shop->woocommerce_credentials = $creds;
        if ($shop->wc_shop_token === null || $shop->wc_shop_token === '') {
            $shop->wc_shop_token = (string) Str::ulid();
        }
        $shop->save();

        // With WC REST keys present we can read the catalog — backfill products now
        // (tenant-bound job; idempotent upsert by external id; runs on the sync queue).
        if (! empty($creds['consumer_key']) && ! empty($creds['consumer_secret'])) {
            ImportShopProductsJob::dispatch((int) $shop->getKey());
        }

        return response()->json([
            'ok' => true,
            'shop' => $shop->wc_shop_token,
            'wc_webhook_secret' => $creds['wc_webhook_secret'],
            'products_syncing' => ! empty($creds['consumer_key']),
        ]);
    }

    /** Liveness/health probe for the plugin Settings page. */
    public function verify(Request $request): JsonResponse
    {
        $shop = $this->shop($request);

        return response()->json([
            'ok' => true,
            'connected' => $shop->hasWooConnection() || ! empty($shop->wooCredential('wc_webhook_secret')),
            'plan' => $shop->plan,
        ]);
    }

    private function shop(Request $request): Shop
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get(VerifyWooCommerceSignature::ATTR_SHOP);

        return $shop;
    }
}
