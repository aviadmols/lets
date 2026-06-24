<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Middleware\VerifyWooCommerceSignature;
use App\Jobs\Products\ImportShopProductsJob;
use App\Models\Shop;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The WooCommerce plugin connect handshake (completes the admin onboarding loop). The
 * request is already HMAC-verified + the shop bound by VerifyWooCommerceSignature; the
 * shop is derived ONLY from the verified key. This:
 *   1. verifies the plugin's reported site host matches the admin-entered
 *      woocommerce_domain (a token can only connect the store it was minted for),
 *   2. stores the connection (base_url + a per-shop wc_webhook_secret, plus the WC REST
 *      consumer key/secret when the plugin supplies them) in the encrypted bag,
 *   3. mints a wc_shop_token (the opaque segment future WC webhooks are delivered to),
 *   4. returns the wc_webhook_secret so the plugin can verify SaaS→plugin callbacks.
 */
final class InstallController
{
    public function install(Request $request): JsonResponse
    {
        $shop = $this->shop($request);

        // Domain binding: the reported host must match the minted-for domain.
        $reported = app(WooCommerceShopProvisioner::class)->normalizeDomain((string) $request->input('base_url', ''));
        $expected = (string) ($shop->woocommerce_domain ?? '');
        if ($expected !== '' && $reported !== '' && $reported !== $expected) {
            return response()->json(['error' => 'domain_mismatch', 'expected' => $expected], 422);
        }

        $creds = $shop->woocommerce_credentials ?: [];
        $creds['base_url'] = (string) ($request->input('base_url') ?: ($creds['base_url'] ?? 'https://'.$expected));
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
