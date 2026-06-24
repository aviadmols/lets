<?php

namespace App\Services\WooCommerce\Orders;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Services\Orders\PaidOrderPlanResolver;

/**
 * WooCommerce implementation of PaidOrderPlanResolver (W11 P2) — the analogue of
 * ShopifyPaidOrderPlanResolver. Finds the deposit plan a paid event activates by the
 * plan public_id, which arrives in two shapes for WooCommerce:
 *
 *   1. The PayPlus deposit callback echoes our `more_info` back; WooCommerceDeposit
 *      CallbackController normalizes it onto the payload as `plan_public_id` (the
 *      strongest, most direct signal — the page WAS for this exact plan).
 *   2. A WC order (mode-B gateway / order.paid webhook) carries the plan id in its
 *      `meta_data` under `lets_plan_public_id` (the WooCommerce order-meta link).
 *
 * Tenant-scoped: InstallmentPlan is BelongsToShop and the caller (the callback / the
 * order-paid handler) has already bound the tenant from the verified shop, so a lookup
 * can never cross into another shop's plans.
 *
 * @phpstan-param array<string, mixed> $orderPayload
 */
final class WooCommercePaidOrderPlanResolver implements PaidOrderPlanResolver
{
    // === CONSTANTS ===
    /** The normalized key the deposit callback writes the echoed more_info onto. */
    public const KEY_PLAN_PUBLIC_ID = 'plan_public_id';

    /** The WooCommerce order meta key linking a paid order to its LETS plan. */
    public const META_PLAN_PUBLIC_ID = 'lets_plan_public_id';

    public function resolve(Shop $shop, array $orderPayload): ?InstallmentPlan
    {
        $publicId = $this->planPublicId($orderPayload);
        if ($publicId === '') {
            return null; // not a LETS deposit we own
        }

        // Tenant-scoped by BelongsToShop; the bound tenant is $shop.
        return InstallmentPlan::query()->where('public_id', $publicId)->first();
    }

    /**
     * Extract the plan public id from either the normalized callback key or a WC
     * order's meta_data ([{key, value}], the WC REST order meta shape).
     *
     * @param  array<string, mixed>  $payload
     */
    private function planPublicId(array $payload): string
    {
        // 1) Direct: the deposit callback normalized the echoed more_info onto the body.
        $direct = (string) (data_get($payload, self::KEY_PLAN_PUBLIC_ID) ?? '');
        if ($direct !== '') {
            return $direct;
        }

        // 2) WC order meta_data: an array of {key, value} pairs.
        foreach ((array) data_get($payload, 'meta_data', []) as $meta) {
            if ((($meta['key'] ?? null) === self::META_PLAN_PUBLIC_ID) && ! empty($meta['value'])) {
                return (string) $meta['value'];
            }
        }

        return '';
    }
}
