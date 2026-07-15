<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Models\Shop;
use App\Services\WooCommerce\Orders\WooGatewayFinalizer;
use App\Services\WooCommerce\WooPluginNotifier;
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

    /**
     * Config flag: when TRUE, a callback WITHOUT a valid signature is rejected (401);
     * when FALSE (default), the signature is verified only when present (today's
     * behaviour). @see config/woocommerce.php
     */
    private const CONFIG_REQUIRE_SIGNATURE = 'woocommerce.require_callback_signature';

    public function __invoke(Request $request, string $wc_shop_token): JsonResponse
    {
        $shop = Shop::query()
            ->where('wc_shop_token', $wc_shop_token)
            ->where('platform', Shop::PLATFORM_WOOCOMMERCE)
            ->first();

        if ($shop === null) {
            return response()->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        // Signature check, selected by config('woocommerce.require_callback_signature'):
        //   OPTIONAL (default, FALSE): verify only when PayPlus sent a hash header.
        //   MANDATORY (TRUE): a callback that LACKS a valid signature is rejected (401);
        //   an empty per-shop secret (cannot verify) → 503 (fail-closed).
        $sentHash = (string) $request->header(self::HASH_HEADER, '');
        $secret = (string) ($shop->payplusCredential('secret_key') ?? '');
        $requireSignature = (bool) config(self::CONFIG_REQUIRE_SIGNATURE, false);

        if ($requireSignature && $secret === '') {
            Log::error('woocommerce.gateway.callback_missing_secret', ['shop_id' => $shop->getKey()]);

            return response()->json(['error' => 'service_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($requireSignature && $sentHash === '') {
            Log::warning('woocommerce.gateway.callback_unsigned_rejected', ['shop_id' => $shop->getKey()]);

            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

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

        // Not one of our gateway orders → nothing to do.
        if (! str_starts_with($moreInfo, self::MORE_INFO_PREFIX)) {
            return response()->json(['ok' => true, 'paid' => false]);
        }

        // A FAILED gateway payment (send_failure_callback made PayPlus call us on decline too).
        // Until W16 this returned silently; now we log it and notify the plugin so the site
        // admin gets an email + the activity log records it. Never a charge, never marks paid.
        if (! in_array($statusCode, self::SUCCESS_CODES, true)) {
            $failedOrderId = substr($moreInfo, strlen(self::MORE_INFO_PREFIX));
            Log::warning('woocommerce.gateway.payment_failed', [
                'shop_id' => $shop->getKey(),
                'order_id' => $failedOrderId,
                'status_code' => $statusCode,
            ]);

            // Fire-and-forget: a notification problem must never change the callback outcome.
            try {
                app(WooPluginNotifier::class)->paymentFailed(
                    $shop,
                    $failedOrderId,
                    $statusCode,
                    (string) (data_get($payload, 'transaction.status_description')
                        ?? data_get($payload, 'status_description') ?? ''),
                );
            } catch (\Throwable $e) {
                Log::warning('woocommerce.gateway.notify_failed', [
                    'shop_id' => $shop->getKey(), 'order_id' => $failedOrderId, 'error' => $e->getMessage(),
                ]);
            }

            return response()->json(['ok' => true, 'paid' => false]);
        }

        $orderId = substr($moreInfo, strlen(self::MORE_INFO_PREFIX));

        // Mark paid + vault the token (shared with the verify-on-return pull path).
        $paid = app(WooGatewayFinalizer::class)->finalizePaid($shop, $orderId, $payload);

        return response()->json(['ok' => true, 'paid' => $paid]);
    }
}
