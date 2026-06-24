<?php

namespace App\Services\Orders;

use App\Models\InstallmentPlan;
use App\Models\Shop;

/**
 * Platform-neutral "find the deposit plan this paid order activates" contract. Each
 * platform encodes the plan→order link differently:
 *   - Shopify     → ShopifyPaidOrderPlanResolver (note_attributes + draft_order_id)
 *   - WooCommerce → WooCommercePaidOrderPlanResolver (order meta_data, W11 P2)
 *
 * The rest of PlanActivationService (token capture, ledger, deposit slot, consent,
 * activation, idempotency) is platform-neutral and unchanged; ONLY the lookup is
 * platform-shaped, which is what this seam isolates.
 *
 * @param  array<string, mixed>  $orderPayload  the paid-order webhook body
 */
interface PaidOrderPlanResolver
{
    public function resolve(Shop $shop, array $orderPayload): ?InstallmentPlan;
}
