<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Installments\DepositPlanService;
use App\Domain\Installments\PlanActivationService;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\PayPlus\PayPlusCallbackVerifier;
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
 *   1. The {wc_shop_token} segment routes the callback to a shop. An unknown/blank
 *      token → 404; we never reveal which shops exist. It is NOT treated as proof
 *      of payment: until the return URLs stopped carrying it, any paying shopper
 *      could read it.
 *   2. If PayPlus signs the body (the `hash` header = base64(HMAC-SHA256(rawBody,
 *      secret_key))), we verify it against the shop's PayPlus secret_key and FAIL
 *      CLOSED (401) on mismatch. An ABSENT signature is refused only when
 *      config('woocommerce.require_callback_signature') is on.
 *   3. The body's "status 000" is a claim. PayPlusCallbackVerifier asks PayPlus's own
 *      IPN about the page WE minted for this plan (the page id stored at mint time)
 *      and activates only when PayPlus holds an APPROVED transaction carrying this
 *      plan's public_id, for at least the stored first-payment amount, in the plan's
 *      currency. The card vaulted is the one PayPlus reports for that page.
 *   4. Money is NEVER taken from the callback body: PlanActivationService records the
 *      deposit at the plan's STORED quote amount and is idempotent on the plan's
 *      deposit key — a replayed callback activates a plan AT MOST once.
 *
 * Tenant law: the shop comes ONLY from the token segment; the tenant is bound for the
 * lookup + activation and cleared after. Money law: ledger-before-charge holds — the
 * PayPlus page already collected the deposit; we only RECORD it, once PayPlus says so.
 */
final class WooDepositCallbackController
{
    // === CONSTANTS ===
    /** PayPlus success status code on the callback / transaction (legacy "000" + worded "approved"). */
    private const SUCCESS_CODES = ['000', '0', 'approved', 'success'];

    private const LOG_PREFIX = 'woocommerce.deposit';

    /** Only a plan still waiting for its first payment can be activated by a page. */
    private const ACTIVATABLE = [PlanStatus::DRAFT, PlanStatus::AWAITING_FIRST_PAYMENT];

    public function __invoke(Request $request, string $wc_shop_token, PayPlusCallbackVerifier $verifier): JsonResponse
    {
        // resolveByCallbackToken() also accepts the token this shop was rotated
        // FROM, while its grace window is open — see CallbackTokenRotator.
        $shop = Shop::resolveByCallbackToken($wc_shop_token, Shop::PLATFORM_WOOCOMMERCE);

        if ($shop === null) {
            // Unknown token → never reveal shop existence; PayPlus retries are harmless.
            return response()->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $signature = $verifier->signature($request, $shop);
        if (($refusal = $verifier->refusal($signature, $shop, self::LOG_PREFIX)) !== null) {
            return $refusal;
        }
        $signed = $signature === PayPlusCallbackVerifier::SIGNATURE_VALID;

        $payload = (array) $request->json()->all();
        $publicId = $this->moreInfo($payload);
        $statusCode = strtolower((string) ($this->statusCode($payload)));

        Log::info(self::LOG_PREFIX.'.callback', [
            'shop_id' => $shop->getKey(),
            'plan_public_id' => $publicId,
            'status_code' => $statusCode,
            'signed' => $signed,
        ]);

        // Only a SUCCESS callback activates; a failure/cancel callback is acknowledged
        // (200) so PayPlus stops retrying, but records nothing.
        if ($publicId === '' || ! in_array($statusCode, self::SUCCESS_CODES, true)) {
            return response()->json(['ok' => true, 'activated' => false]);
        }

        // Tenant-scoped: a public_id of another shop's plan finds nothing here.
        $plan = Tenant::run($shop, static fn (): ?InstallmentPlan => InstallmentPlan::query()
            ->where('public_id', $publicId)
            ->first());

        if ($plan === null) {
            return response()->json(['ok' => true, 'activated' => false]);
        }

        // Already active ⇒ a replayed callback; PlanActivationService is a no-op for it,
        // so there is nothing to confirm and nothing to change.
        if (! in_array($plan->status, self::ACTIVATABLE, true)) {
            return response()->json(['ok' => true, 'activated' => true, 'plan_public_id' => $plan->public_id]);
        }

        // An amount we cannot state is an amount we cannot check — never a free pass.
        $owed = $this->owedAmount($plan);
        if ($owed <= 0) {
            Log::warning(self::LOG_PREFIX.'.callback_no_owed_amount', [
                'shop_id' => $shop->getKey(), 'plan_public_id' => $publicId,
            ]);

            return response()->json(['ok' => true, 'activated' => false]);
        }

        $confirmation = $verifier->confirm(
            shop: $shop,
            callback: $payload,
            expectedMoreInfo: (string) $plan->public_id,
            signed: $signed,
            ownPageRequestUid: (string) (data_get($plan->meta, DepositPlanService::META_DRAFT_GID) ?? ''),
            minAmount: $owed,
            currency: (string) $plan->currency,
        );

        if ($confirmation->unavailable()) {
            // PayPlus could not be asked — let it deliver again rather than lose a payment.
            return response()->json(['error' => 'confirmation_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (! $confirmation->confirmed()) {
            Log::warning(self::LOG_PREFIX.'.callback_unconfirmed', [
                'shop_id' => $shop->getKey(),
                'plan_public_id' => $publicId,
                'reason' => $confirmation->reason,
            ]);

            return response()->json(['ok' => true, 'activated' => false]);
        }

        // Normalize the activation payload: the resolver finds the plan by plan_public_id;
        // PlanActivation's amount comes from the plan's stored quote (depositAmountFor
        // falls back to META_DEPOSIT_AMOUNT when total_price is absent), so we deliberately
        // do NOT pass any PayPlus amount as the authoritative total. The token source is
        // PayPlus's CONFIRMED record of the page, never the raw callback.
        $confirmed = $confirmation->body;
        $activationPayload = [
            WooCommercePaidOrderPlanResolver::KEY_PLAN_PUBLIC_ID => $publicId,
            'id' => (string) ($this->transactionUid($confirmed) ?: $publicId),
            'payplus' => $confirmed,
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

    /**
     * What the page was minted to collect: the stored first-payment amount (the deposit
     * slice, or a subscription's first cycle — both under META_DEPOSIT_AMOUNT), else the
     * per-cycle amount. Zero means "unknown" and the caller refuses it.
     */
    private function owedAmount(InstallmentPlan $plan): float
    {
        $stored = (float) (data_get($plan->meta, DepositPlanService::META_DEPOSIT_AMOUNT) ?? 0);

        return round($stored > 0 ? $stored : (float) $plan->installment_amount, 2);
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

    /** The PayPlus transaction uid, tolerant of the IPN + callback shapes. */
    private function transactionUid(array $payload): string
    {
        return (string) (
            data_get($payload, 'data.transaction.uid')
            ?? data_get($payload, 'data.transaction_uid')
            ?? data_get($payload, 'transaction.uid')
            ?? data_get($payload, 'transaction_uid')
            ?? ''
        );
    }
}
