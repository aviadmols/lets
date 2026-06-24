<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Models\Shop;
use App\Services\WooCommerce\WooClientFactory;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * PayPlus → SaaS GATEWAY callback (the full PayPlus gateway, "mode B"). After the shopper
 * pays a NORMAL WooCommerce order on the PayPlus page, PayPlus POSTs here; the opaque
 * {wc_shop_token} segment resolves the shop BEFORE any field in the body is trusted. On a
 * success status carrying our gateway more_info (gw:{order_id}), we mark the WC order paid
 * via the WC REST API (status processing, set_paid=true).
 *
 * Trust model mirrors WooDepositCallbackController: token segment (per-shop secret) + an
 * OPTIONAL PayPlus `hash` header verified against the shop's PayPlus secret_key (fail
 * closed when present-but-wrong; not all accounts sign). Marking an order paid is
 * idempotent at WooCommerce's side (set_paid on an already-paid order is a no-op), so a
 * replayed callback is safe. No LETS ledger row — a plain checkout's money is WooCommerce's
 * record, not a LETS plan.
 */
final class WooGatewayCallbackController
{
    // === CONSTANTS ===
    private const SUCCESS_CODES = ['000', '0', 'approved', 'success'];
    private const HASH_HEADER = 'hash';
    private const MORE_INFO_PREFIX = 'gw:';

    public function __invoke(Request $request, string $wc_shop_token): JsonResponse
    {
        $shop = Shop::query()
            ->where('wc_shop_token', $wc_shop_token)
            ->where('platform', Shop::PLATFORM_WOOCOMMERCE)
            ->first();

        if ($shop === null) {
            return response()->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        // OPTIONAL signature check: enforced only when PayPlus sent a hash header.
        $sentHash = (string) $request->header(self::HASH_HEADER, '');
        $secret = (string) ($shop->payplusCredential('secret_key') ?? '');
        if ($sentHash !== '' && $secret !== '') {
            $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
            if (! hash_equals($expected, $sentHash)) {
                Log::warning('woocommerce.gateway.callback_bad_signature', ['shop_id' => $shop->getKey()]);

                return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
            }
        }

        $payload = (array) $request->json()->all();
        $moreInfo = (string) (
            data_get($payload, 'transaction.more_info')
            ?? data_get($payload, 'more_info')
            ?? ''
        );
        $statusCode = strtolower((string) (
            data_get($payload, 'transaction.status_code')
            ?? data_get($payload, 'status_code')
            ?? data_get($payload, 'status')
            ?? ''
        ));

        // Only our gateway orders (gw:{id}) on a SUCCESS status mark an order paid.
        if (! str_starts_with($moreInfo, self::MORE_INFO_PREFIX) || ! in_array($statusCode, self::SUCCESS_CODES, true)) {
            return response()->json(['ok' => true, 'paid' => false]);
        }

        $orderId = substr($moreInfo, strlen(self::MORE_INFO_PREFIX));
        if ($orderId === '' || ! $shop->hasWooConnection()) {
            return response()->json(['ok' => true, 'paid' => false]);
        }

        $paid = Tenant::run($shop, function () use ($shop, $orderId): bool {
            try {
                WooClientFactory::for($shop)->updateOrder($orderId, [
                    'status' => 'processing',
                    'set_paid' => true,
                ]);

                return true;
            } catch (\Throwable $e) {
                Log::error('woocommerce.gateway.mark_paid_failed', [
                    'shop_id' => $shop->getKey(), 'order_id' => $orderId, 'error' => $e->getMessage(),
                ]);

                return false;
            }
        });

        return response()->json(['ok' => true, 'paid' => $paid]);
    }
}
