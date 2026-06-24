<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Installments\PlanActivationService;
use App\Models\Shop;
use App\Services\WooCommerce\Orders\WooCommercePaidOrderPlanResolver;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * PayPlus → SaaS deposit-payment CALLBACK (the WooCommerce analogue of Shopify's
 * orders/paid). After the shopper pays the deposit on the PayPlus hosted page, PayPlus
 * POSTs here (refURL_callback). The URL carries the shop's opaque {wc_shop_token} so we
 * resolve the shop BEFORE trusting any field in the body — the same fail-closed pattern
 * as the WC webhook delivery URL.
 *
 * Trust model (defense-in-depth, no single trusted field):
 *   1. The {wc_shop_token} segment is a per-shop secret (ULID, never exposed publicly).
 *      An unknown/blank token → 404; we never reveal which shops exist.
 *   2. If PayPlus signs the body (the `hash` header = base64(HMAC-SHA256(rawBody,
 *      secret_key)) on accounts that emit it), we verify it against the shop's PayPlus
 *      secret_key and FAIL CLOSED (401) on mismatch. When no hash header is present we
 *      do NOT 401 (not all PayPlus accounts sign callbacks) — instead the body is treated
 *      as a HINT and money is gated below.
 *   3. Money is NEVER taken from the callback body: PlanActivationService records the
 *      deposit at the plan's STORED quote amount (DepositPlanService::META_DEPOSIT_AMOUNT),
 *      not what the callback claims, and is idempotent on the plan's deposit key — a
 *      replayed (or forged) callback activates a plan AT MOST once, for the exact amount
 *      we already computed server-side. Only a `success` status_code activates.
 *
 * Tenant law: the shop comes ONLY from the verified token segment; the tenant is bound
 * for the activation and cleared after. Money law: ledger-before-charge holds — the
 * PayPlus page already collected the deposit; we only RECORD it.
 */
final class WooDepositCallbackController
{
    // === CONSTANTS ===
    /** PayPlus success status code on the callback / transaction (legacy "000" + worded "approved"). */
    private const SUCCESS_CODES = ['000', '0', 'approved', 'success'];

    /** The header PayPlus uses to sign the raw callback body (when the account emits it). */
    private const HASH_HEADER = 'hash';

    public function __invoke(Request $request, string $wc_shop_token): JsonResponse
    {
        $shop = Shop::query()
            ->where('wc_shop_token', $wc_shop_token)
            ->where('platform', Shop::PLATFORM_WOOCOMMERCE)
            ->first();

        if ($shop === null) {
            // Unknown token → never reveal shop existence; PayPlus retries are harmless.
            return response()->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        // OPTIONAL signature check: only enforced when PayPlus sent a hash header. A
        // present-but-wrong signature fails closed; an absent one falls through to the
        // money-gated activation (the body can only ever activate the plan it names, once).
        $raw = $request->getContent();
        $sentHash = (string) $request->header(self::HASH_HEADER, '');
        $secret = (string) ($shop->payplusCredential('secret_key') ?? '');
        if ($sentHash !== '' && $secret !== '') {
            $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));
            if (! hash_equals($expected, $sentHash)) {
                Log::warning('woocommerce.deposit.callback_bad_signature', ['shop_id' => $shop->getKey()]);

                return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
            }
        }

        $payload = (array) $request->json()->all();
        $publicId = $this->moreInfo($payload);
        $statusCode = strtolower((string) ($this->statusCode($payload)));

        Log::info('woocommerce.deposit.callback', [
            'shop_id' => $shop->getKey(),
            'plan_public_id' => $publicId,
            'status_code' => $statusCode,
        ]);

        // Only a SUCCESS callback activates; a failure/cancel callback is acknowledged
        // (200) so PayPlus stops retrying, but records nothing.
        if ($publicId === '' || ! in_array($statusCode, self::SUCCESS_CODES, true)) {
            return response()->json(['ok' => true, 'activated' => false]);
        }

        // Normalize the activation payload: the resolver finds the plan by plan_public_id;
        // PlanActivation 's amount comes from the plan's stored quote (depositAmountFor
        // falls back to META_DEPOSIT_AMOUNT when total_price is absent), so we deliberately
        // do NOT pass the callback's amount as the authoritative total.
        $activationPayload = [
            WooCommercePaidOrderPlanResolver::KEY_PLAN_PUBLIC_ID => $publicId,
            'id' => (string) ($this->transactionUid($payload) ?: $publicId),
            'payplus' => $payload,
        ];

        $plan = Tenant::run($shop, function () use ($shop, $activationPayload) {
            return app(PlanActivationService::class)->activateFromPaidOrder($shop, $activationPayload);
        });

        return response()->json([
            'ok' => true,
            'activated' => $plan !== null,
            'plan_public_id' => $plan?->public_id,
        ]);
    }

    /** The echoed more_info (= plan public_id), tolerant of nested/flat PayPlus shapes. */
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

    /** The PayPlus transaction uid, tolerant of nested/flat shapes. */
    private function transactionUid(array $payload): string
    {
        return (string) (
            data_get($payload, 'transaction.uid')
            ?? data_get($payload, 'transaction_uid')
            ?? data_get($payload, 'data.transaction_uid')
            ?? ''
        );
    }
}
