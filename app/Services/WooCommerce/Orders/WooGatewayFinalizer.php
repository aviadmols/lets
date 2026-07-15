<?php

namespace App\Services\WooCommerce\Orders;

use App\Models\InstallmentPaymentMethod;
use App\Models\Shop;
use App\Services\WooCommerce\WooClientFactory;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Finalises a paid WooCommerce gateway order: marks it paid via the WC REST API AND vaults the
 * reusable PayPlus token (so the one-click thank-you upsell has a card to charge).
 *
 * ONE place for this, called by BOTH confirmation paths:
 *   - WooGatewayCallbackController — PayPlus PUSHES the callback (when it does).
 *   - WooGatewayVerifyController   — the plugin PULLS on the thank-you page (verify-on-return),
 *     the reliable path when PayPlus doesn't push (which is why orders were stuck "pending").
 *
 * Idempotent: WooCommerce set_paid on an already-paid order is a no-op, and the vault dedupes on
 * the decrypted token — so a push + a pull, or a replay, finalise exactly once.
 *
 * Never throws: the money already moved on the PayPlus page; a WC/vault hiccup is logged, not
 * propagated, so it can't leave the caller in a bad state.
 */
final class WooGatewayFinalizer
{
    public function __construct(private readonly WooDepositTokenResolver $tokenResolver) {}

    /**
     * @param  array<string, mixed>  $payplusBody  the PayPlus body carrying the token + card
     *                                             meta — the raw callback body, or the IPN/
     *                                             transaction body from a verify-on-return pull.
     * @return bool  true when the order is (now, or already) marked paid
     */
    public function finalizePaid(Shop $shop, string $orderId, array $payplusBody): bool
    {
        if ($orderId === '' || ! $shop->hasWooConnection()) {
            return false;
        }

        return Tenant::run($shop, function () use ($shop, $orderId, $payplusBody): bool {
            try {
                $order = WooClientFactory::for($shop)->updateOrder($orderId, [
                    'status' => 'processing',
                    'set_paid' => true,
                ]);

                // Vault problems must NEVER un-pay a paid order — log, don't throw.
                try {
                    $this->vaultToken($shop, $payplusBody, $order);
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
    }

    /**
     * Save the reusable PayPlus token as an InstallmentPaymentMethod, keyed by the SAME customer
     * ref the thank-you widget sends (WC customer id, else the billing email for a guest) — or
     * UpsellChargeService::resolvePaymentMethod can never match it. Reuses WooDepositTokenResolver
     * (searches every known PayPlus token path; logs observed keys on a miss).
     *
     * @param  array<string, mixed>  $payplusBody
     * @param  array<string, mixed>  $order        the WC order returned by the paid update
     */
    private function vaultToken(Shop $shop, array $payplusBody, array $order): void
    {
        $token = $this->tokenResolver->resolveFromOrder($shop, $payplusBody);

        if ($token === null || ($token['payplus_card_token_uid'] ?? null) === null) {
            return; // no reusable token (create_token off, or PayPlus sent none) — order still paid
        }

        $customerRef = $this->customerRef($order);
        if ($customerRef === '') {
            return;
        }

        // Idempotent. payplus_card_token_uid is an ENCRYPTED cast (ciphertext differs per write),
        // so it can't be matched with a SQL where() — compare the DECRYPTED value in PHP.
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
     * EXACTLY the derivation class-lets-thankyou.php uses: the WC customer id, else the billing
     * email for a guest. The two MUST agree or the vaulted card can't be matched to the shopper.
     *
     * @param  array<string, mixed>  $order
     */
    private function customerRef(array $order): string
    {
        $customerId = (int) ($order['customer_id'] ?? 0);

        return $customerId > 0 ? (string) $customerId : (string) (data_get($order, 'billing.email') ?? '');
    }
}
