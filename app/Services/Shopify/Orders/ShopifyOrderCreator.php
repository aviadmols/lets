<?php

namespace App\Services\Shopify\Orders;

use App\Models\InstallmentPlan;
use App\Services\Shopify\ShopifyAdminApi;
use RuntimeException;

/**
 * Builds the installments PARENT order and recurring/child paid orders. Ported +
 * multi-tenant-refactored from the reference engine's ShopifyOrderCreator — the
 * proven Shopify SHAPE is preserved; the GLOBAL config('shopify.*') client read
 * is replaced by a per-shop ShopifyAdminClient passed in by the strategy.
 *
 * THE PARENT-ORDER-NO-TRANSACTIONS SCAR (preserved verbatim from the reference):
 *   The installments parent order carries NO `transactions` block and stays
 *   financial_status `pending`. Shopify's manual gateway accepts only ONE
 *   auth-capture cycle per order, AND PayPlus's Shopify integration auto-issues a
 *   tax-invoice-receipt for every captured sale on every order it watches —
 *   producing DUPLICATE invoices. Omitting transactions on the parent eliminates
 *   the duplicate at the source. Only child/recurring orders carry the inline
 *   sale transaction (gateway:manual, source:external) — there the real money
 *   already moved through PayPlus and is recorded in the ledger.
 *
 * Idempotent: if the plan already has a shopify_order_id, returns it without
 * calling Shopify (safe for retried activations).
 */
final class ShopifyOrderCreator
{
    public function __construct(private readonly ShopifyAdminApi $client) {}

    /**
     * Create the MAIN/parent Shopify order at FULL product price, LOCKED for
     * fulfillment, with NO transactions (financial_status pending).
     *
     * @return array{shopify_order_id: string, shopify_order_gid: ?string}
     */
    public function createMainOrderForPlan(InstallmentPlan $plan): array
    {
        if ($plan->shopify_order_id !== null && $plan->shopify_order_id !== '') {
            return [
                'shopify_order_id' => (string) $plan->shopify_order_id,
                'shopify_order_gid' => $plan->shopify_order_gid,
            ];
        }

        $ns = (string) config('shopify.metafield_namespace');
        $tags = (array) config('shopify.tags');

        $payload = [
            'email' => $plan->customer_email,
            'currency' => $plan->currency,
            'source_name' => (string) config('shopify.order_source_name'),
            'financial_status' => 'pending',           // NO transactions on parent.
            'inventory_behaviour' => 'decrement_obeying_policy',
            'tags' => implode(', ', array_values(array_filter([
                $tags['installments_active'] ?? null,
                $tags['installments_hold'] ?? null,
            ]))),
            'send_receipt' => false,
            'send_fulfillment_receipt' => false,
            'customer' => array_filter([
                'email' => $plan->customer_email,
                'first_name' => $plan->customer_name,
                'phone' => $plan->customer_phone,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            'line_items' => [[
                'variant_id' => (int) $plan->shopify_variant_id,
                'quantity' => 1,
                'price' => number_format((float) $plan->total_amount, 2, '.', ''),
            ]],
            'note_attributes' => [
                ['name' => 'pps_plan_public_id', 'value' => (string) $plan->public_id],
                ['name' => 'pps_order_role', 'value' => 'main_order'],
            ],
        ];

        $order = $this->client->createOrder($payload);
        $orderId = (string) ($order['id'] ?? '');
        if ($orderId === '') {
            throw new RuntimeException('Shopify did not return a main-order id.');
        }

        // Lock metafields. Failure here must not lose the order id (the strategy
        // persists it first); tolerate metafield setter edge cases.
        try {
            $this->client->setOrderMetafield($orderId, $ns, (string) config('shopify.metafields.plan_public_id'), (string) $plan->public_id);
            $this->client->setOrderMetafield($orderId, $ns, (string) config('shopify.metafields.fulfillment_lock'), 'true', 'boolean');
            $this->client->setOrderMetafield($orderId, $ns, (string) config('shopify.metafields.installment_status'), 'active');
        } catch (\Throwable) {
            // note_attributes already carry the plan id; lock can be re-asserted.
        }

        return [
            'shopify_order_id' => $orderId,
            'shopify_order_gid' => (string) ($order['admin_graphql_api_id'] ?? '') ?: null,
        ];
    }

    /**
     * Create a PAID, fulfillable recurring/child order for one cycle. Carries the
     * inline sale transaction so Shopify shows Paid without us holding card data —
     * the real money already moved through PayPlus.
     *
     * @return array{shopify_order_id: string, shopify_order_gid: ?string, shopify_order_name: ?string}
     */
    public function createPaidRecurringOrderForPayment(InstallmentPlan $plan, float $amount): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Recurring payment amount must be positive.');
        }

        $tags = (array) config('shopify.tags');

        $payload = [
            'email' => (string) $plan->customer_email,
            'currency' => (string) $plan->currency,
            'source_name' => (string) config('shopify.order_source_name'),
            'tags' => implode(', ', array_values(array_filter([
                $tags['recurring_order'] ?? null,
                $tags['payment_order'] ?? null,
            ]))),
            'send_receipt' => false,
            'send_fulfillment_receipt' => false,
            'customer' => array_filter([
                'email' => (string) $plan->customer_email,
                'first_name' => (string) $plan->customer_name,
                'phone' => (string) $plan->customer_phone,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            'line_items' => [[
                'title' => sprintf('Subscription cycle — plan %s', (string) $plan->public_id),
                'price' => number_format($amount, 2, '.', ''),
                'quantity' => 1,
                'requires_shipping' => true,
            ]],
            'note_attributes' => [
                ['name' => 'pps_plan_public_id', 'value' => (string) $plan->public_id],
                ['name' => 'pps_order_role', 'value' => 'recurring_order'],
                ['name' => 'pps_main_order_id', 'value' => (string) $plan->shopify_order_id],
            ],
            // Inline sale tx — child/recurring ONLY (never the parent). The money
            // already moved through PayPlus; this just shows Paid in Shopify.
            'transactions' => [[
                'kind' => 'sale',
                'status' => 'success',
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => (string) $plan->currency,
                'gateway' => (string) config('shopify.order_tx_gateway', 'manual'),
                'source' => (string) config('shopify.order_tx_source', 'external'),
            ]],
        ];

        $order = $this->client->createOrder($payload);
        $orderId = (string) ($order['id'] ?? '');
        if ($orderId === '') {
            throw new RuntimeException('Shopify did not return a recurring-order id.');
        }

        return [
            'shopify_order_id' => $orderId,
            'shopify_order_gid' => (string) ($order['admin_graphql_api_id'] ?? '') ?: null,
            'shopify_order_name' => (string) ($order['name'] ?? '') ?: null,
        ];
    }
}
