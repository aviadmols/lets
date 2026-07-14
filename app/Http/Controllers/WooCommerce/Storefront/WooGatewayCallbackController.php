<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Models\InstallmentPaymentMethod;
use App\Models\Shop;
use App\Services\WooCommerce\Orders\WooDepositTokenResolver;
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

        // Only our gateway orders (gw:{id}) on a SUCCESS status mark an order paid.
        if (! str_starts_with($moreInfo, self::MORE_INFO_PREFIX) || ! in_array($statusCode, self::SUCCESS_CODES, true)) {
            return response()->json(['ok' => true, 'paid' => false]);
        }

        $orderId = substr($moreInfo, strlen(self::MORE_INFO_PREFIX));
        if ($orderId === '' || ! $shop->hasWooConnection()) {
            return response()->json(['ok' => true, 'paid' => false]);
        }

        $paid = Tenant::run($shop, function () use ($shop, $orderId, $payload): bool {
            try {
                $order = WooClientFactory::for($shop)->updateOrder($orderId, [
                    'status' => 'processing',
                    'set_paid' => true,
                ]);

                // Vault the reusable card token, if PayPlus returned one. Until now a plain
                // checkout saved NOTHING — which is why the one-click thank-you upsell could
                // never charge. Requires create_token on the shop's page settings.
                // Never let a vault problem un-pay a paid order: it is logged, not thrown.
                try {
                    $this->vaultToken($shop, $payload, $order);
                } catch (\Throwable $e) {
                    Log::warning('woocommerce.gateway.vault_failed', [
                        'shop_id' => $shop->getKey(), 'order_id' => $orderId, 'error' => $e->getMessage(),
                    ]);
                }

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

    /**
     * Save the reusable PayPlus token from this callback as an InstallmentPaymentMethod,
     * keyed by the SAME customer ref the thank-you widget sends (WC customer id, else the
     * billing email for a guest) — so UpsellChargeService::resolvePaymentMethod finds it.
     *
     * Reuses WooDepositTokenResolver, which searches every known PayPlus token path AND logs
     * the observed top-level keys when it finds none — so a wrong field name self-diagnoses
     * in the logs instead of failing silently.
     *
     * @param  array<string, mixed>  $payload  the raw PayPlus callback body
     * @param  array<string, mixed>  $order    the WC order as returned by the paid update
     */
    private function vaultToken(Shop $shop, array $payload, array $order): void
    {
        $token = app(WooDepositTokenResolver::class)->resolveFromOrder($shop, $payload);

        // No reusable token (create_token off, or PayPlus sent none). The order is still
        // paid — only the later one-click upsell needs the card.
        if ($token === null || ($token['payplus_card_token_uid'] ?? null) === null) {
            return;
        }

        $customerRef = $this->customerRef($order);
        if ($customerRef === '') {
            return; // no identity to match a future offer against
        }

        // Idempotent: a replayed callback must never vault the same card twice.
        // NOTE payplus_card_token_uid is an ENCRYPTED cast — its ciphertext differs on every
        // write, so it can NEVER be matched with a SQL where(). Compare the DECRYPTED value in
        // PHP over this customer's (few) active methods instead.
        $alreadyVaulted = InstallmentPaymentMethod::query()
            ->where('shopify_customer_id', $customerRef)
            ->where('status', InstallmentPaymentMethod::STATUS_ACTIVE)
            ->get()
            ->contains(fn (InstallmentPaymentMethod $m): bool => $m->payplus_card_token_uid === $token['payplus_card_token_uid']);

        if ($alreadyVaulted) {
            return;
        }

        InstallmentPaymentMethod::query()->create([
            'shopify_customer_id' => $customerRef,
            'payplus_card_token_uid' => $token['payplus_card_token_uid'],
            'payplus_customer_uid' => $token['payplus_customer_uid'] ?? null,
            'card_brand' => $token['card_brand'] ?? null,
            'card_last_four' => $token['card_last_four'] ?? null,
            'exp_month' => $token['exp_month'] ?? null,
            'exp_year' => $token['exp_year'] ?? null,
            'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
        ]);

        Log::info('woocommerce.gateway.token_vaulted', [
            'shop_id' => $shop->getKey(),
            'order_id' => (string) ($order['id'] ?? ''),
        ]);
    }

    /**
     * EXACTLY the derivation the thank-you widget uses (class-lets-thankyou.php):
     * the WC customer id, or the billing email for a guest. The two MUST agree or the
     * vaulted card can never be matched to the shopper on the thank-you page.
     *
     * @param  array<string, mixed>  $order
     */
    private function customerRef(array $order): string
    {
        $customerId = (int) ($order['customer_id'] ?? 0);

        if ($customerId > 0) {
            return (string) $customerId;
        }

        return (string) (data_get($order, 'billing.email') ?? '');
    }
}
