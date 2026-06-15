<?php

namespace App\Services\Shopify\Orders;

use App\Models\InstallmentPlan;
use App\Services\Shopify\ShopifyAdminApi;

/**
 * The draft-order → complete-as-paid pattern. Ported (lean) + multi-tenant from
 * the reference ShopifyDraftOrderService. This is the locked Shopify shape for
 * the post-purchase UPSELL child order (ARCHITECTURE.md): create a draft linked
 * to the parent, complete it as paid (the money already moved on the saved
 * PayPlus token), yielding a separate linked child order — no order-edit, no
 * external-payment reconciliation issues.
 *
 * Exposed now as the SEAM Phase 6 builds on; the full upsell flow (offer
 * resolution, charge, accept/decline) is out of this run's scope.
 *
 * @see DefaultShopifyOrderStrategy::onUpsell() — the Phase-6 call site.
 */
final class ShopifyDraftOrderService
{
    public function __construct(private readonly ShopifyAdminApi $client) {}

    /**
     * Create a linked child order for an upsell, as a completed-as-paid draft.
     *
     * @param  array{title: string, price: float, quantity?: int}  $lineItem
     * @return array{shopify_order_id: string, shopify_order_gid: ?string}
     */
    public function createUpsellChildOrder(InstallmentPlan $plan, string $parentOrderId, array $lineItem): array
    {
        $tags = (array) config('shopify.tags');

        $draft = $this->client->createDraftOrder([
            'email' => (string) $plan->customer_email,
            'currency' => (string) $plan->currency,
            'tags' => (string) ($tags['upsell_child'] ?? 'upsell-child'),
            'note_attributes' => [
                ['name' => 'pps_main_order_id', 'value' => $parentOrderId],
                ['name' => 'pps_order_role', 'value' => 'upsell_child'],
                ['name' => 'pps_plan_public_id', 'value' => (string) $plan->public_id],
            ],
            'line_items' => [[
                'title' => (string) $lineItem['title'],
                'price' => number_format((float) $lineItem['price'], 2, '.', ''),
                'quantity' => (int) ($lineItem['quantity'] ?? 1),
            ]],
        ]);

        $draftId = (string) ($draft['id'] ?? '');

        // Complete as PAID (payment_pending=false): the money already moved on the
        // saved token via laravel-backend; this just records the paid child order.
        $order = $this->client->completeDraftOrder($draftId, paymentPending: false);

        return [
            'shopify_order_id' => (string) ($order['order_id'] ?? $order['id'] ?? ''),
            'shopify_order_gid' => (string) ($order['admin_graphql_api_id'] ?? '') ?: null,
        ];
    }
}
