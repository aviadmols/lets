<?php

namespace App\Services\Orders;

use App\Domain\Installments\Contracts\DepositTokenResolver;
use App\Models\Shop;
use App\Services\WooCommerce\Orders\WooDepositTokenResolver;

/**
 * Platform router for DepositTokenResolver (defensive follow-up wiring).
 *
 * PlanActivationService is platform-NEUTRAL: the SAME instance activates both Shopify
 * and WooCommerce deposit plans, and it captures the reusable PayPlus token through the
 * optional DepositTokenResolver seam. To add the WooCommerce token capture WITHOUT
 * touching the Shopify path, we bind THIS router as the one DepositTokenResolver and let
 * it dispatch on the (verified) shop's `platform`:
 *
 *   - WooCommerce → WooDepositTokenResolver (extracts token/customer-ref from the PayPlus
 *                   deposit callback body).
 *   - anything else (Shopify / unknown) → null. This is IDENTICAL to today's behaviour:
 *     before this follow-up no DepositTokenResolver was bound, so the Shopify path
 *     captured no token through this seam (laravel-backend owns the Shopify token capture
 *     elsewhere). Returning null here changes the Shopify path by exactly nothing.
 *
 * The shop is passed explicitly into resolveFromOrder, so routing is on the VERIFIED
 * tenant — never on global state — and a forgotten platform falls through to the safe
 * null (the plan activates without a method; only auto-charges need the token).
 */
final class PlatformDepositTokenResolver implements DepositTokenResolver
{
    public function __construct(private readonly WooDepositTokenResolver $woo) {}

    public function resolveFromOrder(Shop $shop, array $orderPayload): ?array
    {
        return match ($shop->platform) {
            Shop::PLATFORM_WOOCOMMERCE => $this->woo->resolveFromOrder($shop, $orderPayload),
            // Shopify + unknown: no token via this seam (unchanged from before the
            // follow-up — laravel-backend captures the Shopify token elsewhere).
            default => null,
        };
    }
}
