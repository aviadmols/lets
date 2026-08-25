<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Installments\CardUpdateService;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * PayPlus → SaaS CARD-UPDATE callback — the re-vault page's server-to-server
 * completion, on exactly the deposit callback's trust rails:
 *
 *   1. The opaque {wc_shop_token} resolves the shop BEFORE any body field is
 *      trusted; unknown token → 404, and no shop's existence is ever revealed.
 *   2. A present PayPlus `hash` signature is verified against the shop's
 *      secret_key and FAILS CLOSED; enforcement of absent signatures follows the
 *      same config switch as the deposit callback.
 *   3. The body can only ever act on the plan its `cardupd:`-prefixed more_info
 *      names — a deposit callback replayed here matches nothing, and vice versa
 *      — and CardUpdateService is idempotent on the token uid, so a replay
 *      re-points the same plans at the same card and changes nothing.
 *
 * No money moves here in any branch: this endpoint only VAULTS.
 */
final class WooCardUpdateCallbackController
{
    // === CONSTANTS ===
    private const SUCCESS_CODES = ['000', '0', 'approved', 'success'];

    private const HASH_HEADER = 'hash';

    private const CONFIG_REQUIRE_SIGNATURE = 'woocommerce.require_callback_signature';

    public function __invoke(Request $request, string $wc_shop_token): JsonResponse
    {
        $shop = Shop::query()
            ->where('wc_shop_token', $wc_shop_token)
            ->first();

        if ($shop === null) {
            return response()->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $raw = $request->getContent();
        $sentHash = (string) $request->header(self::HASH_HEADER, '');
        $secret = (string) ($shop->payplusCredential('secret_key') ?? '');
        $requireSignature = (bool) config(self::CONFIG_REQUIRE_SIGNATURE, false);

        if ($requireSignature && $secret === '') {
            Log::error('installments.card_update.callback_missing_secret', ['shop_id' => $shop->getKey()]);

            return response()->json(['error' => 'service_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($requireSignature && $sentHash === '') {
            Log::warning('installments.card_update.callback_unsigned_rejected', ['shop_id' => $shop->getKey()]);

            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if ($sentHash !== '' && $secret !== '') {
            $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));
            if (! hash_equals($expected, $sentHash)) {
                Log::warning('installments.card_update.callback_bad_signature', ['shop_id' => $shop->getKey()]);

                return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
            }
        }

        $payload = (array) $request->json()->all();
        $moreInfo = $this->moreInfo($payload);
        $statusCode = strtolower($this->statusCode($payload));

        Log::info('installments.card_update.callback', [
            'shop_id' => $shop->getKey(),
            'more_info' => $moreInfo,
            'status_code' => $statusCode,
        ]);

        // Only OUR marker, and only a success. A failure callback is acknowledged
        // (PayPlus stops retrying) and vaults nothing.
        if (! str_starts_with($moreInfo, CardUpdateService::MORE_INFO_PREFIX)
            || ! in_array($statusCode, self::SUCCESS_CODES, true)) {
            return response()->json(['ok' => true, 'updated' => false]);
        }

        $planPublicId = substr($moreInfo, strlen(CardUpdateService::MORE_INFO_PREFIX));

        $method = Tenant::run($shop, fn () => app(CardUpdateService::class)
            ->applyCallback($shop, $planPublicId, $payload));

        return response()->json(['ok' => true, 'updated' => $method !== null]);
    }

    /** The echoed more_info, tolerant of nested/flat PayPlus shapes. */
    private function moreInfo(array $payload): string
    {
        return (string) (
            data_get($payload, 'transaction.more_info')
            ?? data_get($payload, 'more_info')
            ?? data_get($payload, 'data.transaction.more_info')
            ?? ''
        );
    }

    /** The PayPlus status/result code, tolerant of nested/flat shapes. */
    private function statusCode(array $payload): string
    {
        return (string) (
            data_get($payload, 'transaction.status_code')
            ?? data_get($payload, 'status_code')
            ?? data_get($payload, 'transaction.status')
            ?? data_get($payload, 'status')
            ?? ''
        );
    }
}
