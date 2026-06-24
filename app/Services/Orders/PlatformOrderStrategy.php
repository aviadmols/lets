<?php

namespace App\Services\Orders;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\ChargeContext;

/**
 * Platform-neutral order-strategy contract. The ChargeOrchestrator decides TO
 * CHARGE; AFTER a succeeded ledger row exists it calls the SHOP'S platform strategy
 * to MATERIALIZE store state (create/update/release the order, write metafields/meta).
 * We never materialize for a charge that has not succeeded.
 *
 * One implementation per platform, resolved by PlatformOrderStrategyFactory keyed on
 * $shop->platform:
 *   - Shopify     → App\Services\Shopify\Orders\ShopifyOrderStrategy (extends this)
 *   - WooCommerce → App\Services\WooCommerce\Orders\WooCommerceOrderStrategy (W11 P2)
 *
 * Dispatch is by charge_context (see each implementation): deposit · installment ·
 * final installment · recurring · upsell · retry/manual. Idempotent by construction
 * (guards on the stored external order id / metafield presence) so a double-fired
 * webhook or retried job never creates a second order.
 */
interface PlatformOrderStrategy
{
    /**
     * Materialize store state for one succeeded charge. $isFinal flips the
     * installments completion path (release fulfillment).
     */
    public function materialize(InstallmentPlan $plan, ChargeContext $context, bool $isFinal = false): void;
}
