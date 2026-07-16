<?php

namespace App\Services\WooCommerce\Orders;

use App\Domain\Installments\PlanActivationService;
use App\Models\InstallmentPaymentMethod;
use App\Models\Shop;
use App\Services\WooCommerce\WooClientFactory;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;
// WooCommercePaidOrderPlanResolver is in this same namespace (App\Services\WooCommerce\Orders).

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

                // Record the PayPlus confirmation as a merchant-visible order note (W18) — so the
                // merchant SEES what PayPlus returned. Fail-soft: a note failure never un-pays.
                try {
                    $this->recordConfirmationNote($shop, $orderId, $payplusBody);
                } catch (\Throwable $e) {
                    Log::warning('woocommerce.gateway.note_failed', [
                        'shop_id' => $shop->getKey(), 'order_id' => $orderId, 'error' => $e->getMessage(),
                    ]);
                }

                // Cart-based subscriptions (W17 B): activate each recurring plan this order created.
                // Fail-soft — a plan-activation hiccup must never un-pay a paid order.
                try {
                    $this->activateSubscriptionPlans($shop, $orderId, $order, $payplusBody);
                } catch (\Throwable $e) {
                    Log::warning('woocommerce.gateway.subscription_activate_failed', [
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
     * Add a merchant-visible WooCommerce order note recording what PayPlus returned (W18): the
     * transaction id, status, approval number, amount, and masked card. Also stamps the transaction
     * id as order meta (lets_payplus_transaction_uid) for later lookup. No secrets (no token). The
     * PayPlus body shape differs by path (push transaction.* or root, vs pull data.transaction.*), so
     * every field is searched across both shapes.
     *
     * @param  array<string, mixed>  $payplusBody
     */
    private function recordConfirmationNote(Shop $shop, string $orderId, array $payplusBody): void
    {
        $txnUid = $this->pick($payplusBody, [
            'data.transaction.uid', 'data.transaction.transaction_uid', 'data.transaction_uid',
            'transaction.uid', 'transaction.transaction_uid', 'data.uid', 'uid',
        ]);
        $statusCode = $this->pick($payplusBody, ['data.transaction.status_code', 'transaction.status_code', 'status_code', 'status']);
        $statusDesc = $this->pick($payplusBody, ['data.transaction.status_description', 'transaction.status_description', 'status_description', 'results.description']);
        $approval = $this->pick($payplusBody, ['data.transaction.approval_number', 'transaction.approval_number', 'data.approval_number', 'approval_number']);
        $amount = $this->pick($payplusBody, ['data.transaction.amount', 'transaction.amount', 'data.amount', 'amount']);
        $lastFour = $this->pick($payplusBody, ['data.transaction.four_digits', 'transaction.four_digits', 'data.four_digits', 'four_digits']);
        $brand = $this->pick($payplusBody, ['data.transaction.brand_name', 'transaction.brand_name', 'data.brand_name', 'brand_name']);

        $parts = ['PayPlus payment confirmed.'];
        if ($txnUid !== '') {
            $parts[] = 'Transaction: '.$txnUid;
        }
        if ($statusCode !== '') {
            $parts[] = 'Status: '.$statusCode.($statusDesc !== '' ? ' ('.$statusDesc.')' : '');
        }
        if ($approval !== '') {
            $parts[] = 'Approval: '.$approval;
        }
        if ($amount !== '') {
            $parts[] = 'Amount: '.$amount;
        }
        if ($lastFour !== '') {
            $parts[] = 'Card: ****'.$lastFour.($brand !== '' ? ' '.$brand : '');
        }

        WooClientFactory::for($shop)->addOrderNote($orderId, implode(' · ', $parts), false);

        if ($txnUid !== '') {
            // Non-underscore so it round-trips over the WC REST API for later reconciliation.
            WooClientFactory::for($shop)->updateOrder($orderId, [
                'meta_data' => [['key' => 'lets_payplus_transaction_uid', 'value' => $txnUid]],
            ]);
        }
    }

    /**
     * First non-empty value across a list of dot-paths in the PayPlus body (both push + pull shapes).
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $paths
     */
    private function pick(array $body, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($body, $path);
            if ($value !== null && $value !== '' && ! is_array($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * Activate each cart-based subscription plan the order created (W17 B). The plugin stored the
     * plans' public ids as the order meta `_lets_subscription_plan_ids`; per id we run the SAME
     * activation the deposit/subscribe flow uses — PlanActivationService vaults the token to the
     * plan, records the first cycle as a SUCCEEDED ledger row + CONTEXT_RECURRING consent, sets
     * next_charge_at, and flips awaiting_first_payment → active. It's idempotent (no-ops unless the
     * plan is awaiting), so push + verify-on-return + a replay activate exactly once.
     *
     * @param  array<string, mixed>  $order        the paid WC order (with meta_data)
     * @param  array<string, mixed>  $payplusBody  the PayPlus token/transaction body
     */
    private function activateSubscriptionPlans(Shop $shop, string $orderId, array $order, array $payplusBody): void
    {
        $planIds = $this->subscriptionPlanIds($order);
        if ($planIds === []) {
            return;
        }

        $activator = app(PlanActivationService::class);
        foreach ($planIds as $publicId) {
            // Pass the plan public id so the resolver finds THIS plan, and the PayPlus body so the
            // token vaults to it. Strip any `total_price` so PlanActivationService records the first
            // cycle at the plan's stored per-cycle amount, NOT the whole cart total — never rely on
            // the PayPlus body merely lacking that key.
            $payload = $payplusBody;
            unset($payload['total_price']);
            $payload[WooCommercePaidOrderPlanResolver::KEY_PLAN_PUBLIC_ID] = $publicId;
            $payload['id'] = $orderId;

            $activator->activateFromPaidOrder($shop, $payload);
        }
    }

    /**
     * The subscription plan public ids the plugin stored on the order meta. Read back over the WC
     * REST API, so the key is the NON-underscore `lets_subscription_plan_ids` (WooCommerce omits
     * protected `_`-prefixed meta from REST order responses). WC returns meta_data as [{key, value}];
     * the value may be a real array or a comma-joined string.
     *
     * @param  array<string, mixed>  $order
     * @return list<string>
     */
    private function subscriptionPlanIds(array $order): array
    {
        foreach ((array) ($order['meta_data'] ?? []) as $meta) {
            if (($meta['key'] ?? null) !== 'lets_subscription_plan_ids') {
                continue;
            }
            $value = $meta['value'] ?? null;
            if (is_array($value)) {
                return array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $value), static fn (string $v): bool => $v !== ''));
            }
            if (is_string($value) && $value !== '') {
                return array_values(array_filter(array_map('trim', explode(',', $value))));
            }
        }

        return [];
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
