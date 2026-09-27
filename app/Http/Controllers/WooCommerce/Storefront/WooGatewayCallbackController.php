<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Support\ResponseMasker;
use App\Services\PayPlus\PayPlusCallbackVerifier;
use App\Services\WooCommerce\Orders\WooGatewayFinalizer;
use App\Services\WooCommerce\WooPluginNotifier;
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
 * Trust model mirrors WooDepositCallbackController: the token segment routes to a shop;
 * a PayPlus `hash` header is verified against the shop's secret_key (fail closed when
 * present-but-wrong; mandatory when config says so); and — the wall that holds whether
 * or not PayPlus signed — PayPlusCallbackVerifier asks PayPlus's own IPN about the page
 * and only a transaction PayPlus reports APPROVED, carrying this order's `gw:` marker,
 * reaches the finalizer. The finalizer then checks what PayPlus collected against the WC
 * order total before marking anything paid. Marking an order paid is idempotent at
 * WooCommerce's side (set_paid on an already-paid order is a no-op), so a replayed
 * callback is safe. The ledger row for a successful payment is written at finalize time by
 * WooGatewayFinalizer (context `gateway` — see its docblock for the design reversal); a
 * FAILED attempt is recorded here, since the finalizer only ever sees successes — and only
 * for a page that is provably ours (signed, or held by PayPlus's own record).
 */
final class WooGatewayCallbackController
{
    // === CONSTANTS ===
    private const SUCCESS_CODES = ['000', '0', 'approved', 'success'];

    private const MORE_INFO_PREFIX = 'gw:';

    private const LOG_PREFIX = 'woocommerce.gateway';

    public function __invoke(Request $request, string $wc_shop_token, PayPlusCallbackVerifier $verifier): JsonResponse
    {
        $shop = Shop::query()
            ->where('wc_shop_token', $wc_shop_token)
            ->where('platform', Shop::PLATFORM_WOOCOMMERCE)
            ->first();

        if ($shop === null) {
            return response()->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $signature = $verifier->signature($request, $shop);
        if (($refusal = $verifier->refusal($signature, $shop, self::LOG_PREFIX)) !== null) {
            return $refusal;
        }
        $signed = $signature === PayPlusCallbackVerifier::SIGNATURE_VALID;

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

        // What PayPlus itself says about the page this body names. The amount wall
        // (vs. the WC order total) is the finalizer's: only it reads the order.
        $confirmation = $verifier->confirm($shop, $payload, $moreInfo, $signed);

        if ($confirmation->unavailable()) {
            // PayPlus could not be asked — let it deliver again; verify-on-return also covers it.
            return response()->json(['error' => 'confirmation_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // A FAILED gateway payment (send_failure_callback made PayPlus call us on decline too).
        // Until W16 this returned silently; now we log it and notify the plugin so the site
        // admin gets an email + the activity log records it. Never a charge, never marks paid.
        if (! in_array($statusCode, self::SUCCESS_CODES, true)) {
            $failedOrderId = substr($moreInfo, strlen(self::MORE_INFO_PREFIX));

            // A decline nobody can tie to a genuine page of ours writes no ledger row
            // and emails nobody — a forged "failed" is as unwelcome as a forged "paid".
            if (! $signed && ! $confirmation->bound()) {
                Log::warning('woocommerce.gateway.payment_failed_unconfirmed', [
                    'shop_id' => $shop->getKey(), 'order_id' => $failedOrderId, 'reason' => $confirmation->reason,
                ]);

                return response()->json(['ok' => true, 'paid' => false]);
            }
            Log::warning('woocommerce.gateway.payment_failed', [
                'shop_id' => $shop->getKey(),
                'order_id' => $failedOrderId,
                'status_code' => $statusCode,
            ]);

            // Record the DECLINE in the ledger so the merchant sees it on the
            // Payments screen, not only in a log. Keyed per failed attempt —
            // deliberately NOT the success key, because failed → succeeded is an
            // illegal ledger transition and a later retry that succeeds must land
            // on a fresh row. Fail-soft: recording must never change the outcome.
            try {
                // PayPlus's record of the page when we hold it; else the SIGNED body.
                $this->recordFailedAttempt(
                    $shop,
                    $failedOrderId,
                    $statusCode,
                    $confirmation->bound() ? $confirmation->body : $payload,
                );
            } catch (\Throwable $e) {
                Log::warning('woocommerce.gateway.failed_ledger_failed', [
                    'shop_id' => $shop->getKey(), 'order_id' => $failedOrderId, 'error' => $e->getMessage(),
                ]);
            }

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

        if (! $confirmation->confirmed()) {
            Log::warning('woocommerce.gateway.callback_unconfirmed', [
                'shop_id' => $shop->getKey(), 'order_id' => $orderId, 'reason' => $confirmation->reason,
            ]);

            return response()->json(['ok' => true, 'paid' => false]);
        }

        // Mark paid + vault the token (shared with the verify-on-return pull path) — from
        // PayPlus's CONFIRMED record of the page, never the raw callback body.
        $paid = app(WooGatewayFinalizer::class)->finalizePaid($shop, $orderId, $confirmation->body);

        return response()->json(['ok' => true, 'paid' => $paid]);
    }

    /**
     * One FAILED ledger row per declined attempt. The amount comes from the
     * PayPlus body alone (there is no paid WC order to fall back to); a decline
     * with no readable amount is recorded at 0 — the row exists to SHOW the
     * decline, and inventing a number would be worse than omitting one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordFailedAttempt(Shop $shop, string $orderId, string $statusCode, array $payload): void
    {
        $shopId = (int) $shop->getKey();
        $txnUid = (string) (data_get($payload, 'data.transaction.uid')
            ?? data_get($payload, 'transaction.uid')
            ?? data_get($payload, 'transaction.transaction_uid')
            ?? data_get($payload, 'uid') ?? '');

        // Per-attempt ref: the transaction uid when PayPlus sent one, else a hash
        // of the raw body so two DIFFERENT declines never collapse into one row.
        $ref = $txnUid !== '' ? $txnUid : substr(hash('sha256', json_encode($payload) ?: $statusCode), 0, 16);

        // Tenant bound for the write: shop_id is guarded on PaymentLedger and is
        // stamped by the BelongsToShop creating hook — an unbound create would
        // silently drop it. (The success path inherits the finalizer's binding.)
        Tenant::run($shop, function () use ($shopId, $orderId, $statusCode, $payload, $txnUid, $ref): void {
            $row = Ledger::open(
                shopId: $shopId,
                chargeContext: PaymentLedger::CONTEXT_GATEWAY,
                idempotencyKey: IdempotencyKey::gatewayFailure($shopId, $orderId, $ref),
                amount: (float) (data_get($payload, 'data.transaction.amount')
                    ?? data_get($payload, 'transaction.amount') ?? data_get($payload, 'amount') ?? 0),
                currency: (string) (data_get($payload, 'data.transaction.currency')
                    ?? data_get($payload, 'transaction.currency')
                    ?? data_get($payload, 'currency')
                    ?? config('payplus.currency', 'ILS')),
                attributes: [
                    'shopify_order_id' => $orderId,
                    'payplus_transaction_uid' => $txnUid ?: null,
                ],
            );

            Ledger::transition($row, LedgerStatus::FAILED, [
                'failure_code' => $statusCode ?: null,
                'failure_message' => ((string) (data_get($payload, 'data.transaction.status_description')
                    ?? data_get($payload, 'transaction.status_description')
                    ?? data_get($payload, 'status_description') ?? '')) ?: null,
                'raw_response_masked' => ResponseMasker::mask($payload),
            ]);
        });
    }
}
