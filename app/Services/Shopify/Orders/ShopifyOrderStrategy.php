<?php

namespace App\Services\Shopify\Orders;

use App\Services\Orders\PlatformOrderStrategy;

/**
 * The Shopify order strategy contract (the §5 table in ARCHITECTURE.md) — the
 * Shopify implementation of the platform-neutral PlatformOrderStrategy. The
 * orchestrator (laravel-backend) decides TO CHARGE; AFTER a succeeded ledger row
 * exists it calls materialize() to MATERIALIZE Shopify state. We never create a
 * fulfillable order for a charge that has not succeeded.
 *
 * Dispatch is by charge_context:
 *   deposit            → create the installments PARENT order, LOCKED, NO tx.
 *   installment        → update the parent's metafields (paid/remaining/next).
 *   final installment  → release fulfillment (markAsPaid + createFulfillment).
 *   recurring          → a new fulfillable order per cycle.
 *   upsell             → a linked child order (PHASE 6 — TODO, not here).
 *   retry/manual       → re-enter the matching context.
 *
 * Every method takes the plan/shop EXPLICITLY (no global shop) and resolves its
 * client via ShopifyClientFactory::for($plan->shop). Idempotent by construction
 * (guards on shopify_order_id / metafield presence) so a double-fired webhook or
 * retried job never creates a second order.
 *
 * The materialize() signature is inherited from PlatformOrderStrategy (one contract
 * shared across platforms); this marker interface keeps the Shopify DI binding +
 * the existing Shopify type-hints stable.
 */
interface ShopifyOrderStrategy extends PlatformOrderStrategy
{
}
