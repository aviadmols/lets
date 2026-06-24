<?php

namespace App\Services\Shopify\Orders;

use App\Domain\Installments\DepositPlanService;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Services\Orders\PaidOrderPlanResolver;

/**
 * Shopify implementation of PaidOrderPlanResolver — the exact lookup PlanActivation
 * Service used before the W11 platform seam (behaviour byte-identical). Finds the
 * deposit plan a paid Shopify order activates by:
 *   1. the order's `pps_plan_public_id` note/custom attribute (strongest signal), then
 *   2. the source draft id (`draft_order_id`) matched against the plan meta
 *      DepositPlanService::META_DRAFT_ID (DepositPlanService stored the draft's
 *      legacyResourceId there).
 *
 * Tenant-scoped: InstallmentPlan is BelongsToShop, and the caller (OrderPaidHandler)
 * has already bound the tenant from the verified webhook shop.
 */
final class ShopifyPaidOrderPlanResolver implements PaidOrderPlanResolver
{
    // === CONSTANTS ===
    /** The custom-attribute key the deposit draft/order carries (links to the plan). */
    private const ATTR_PLAN_PUBLIC_ID = 'pps_plan_public_id';

    public function resolve(Shop $shop, array $orderPayload): ?InstallmentPlan
    {
        // Strongest signal: the order carries our plan public id (copied from the
        // deposit draft's customAttributes onto the order's note_attributes).
        $publicId = $this->attribute($orderPayload, self::ATTR_PLAN_PUBLIC_ID);
        if ($publicId !== null && $publicId !== '') {
            $plan = InstallmentPlan::query()->where('public_id', $publicId)->first();
            if ($plan !== null) {
                return $plan;
            }
        }

        // Fallback: the draft that became this order. DepositPlanService stored the
        // draft's legacyResourceId in the plan meta; orders/paid carries the source
        // draft id in `draft_order_id`.
        $draftId = (string) (data_get($orderPayload, 'draft_order_id') ?? '');
        if ($draftId !== '') {
            $plan = InstallmentPlan::query()
                ->where('meta->'.DepositPlanService::META_DRAFT_ID, $draftId)
                ->first();
            if ($plan !== null) {
                return $plan;
            }
        }

        return null; // not a LETS deposit order we own
    }

    /**
     * Read a note/custom attribute by name from the order payload. Shopify exposes
     * draft customAttributes on the resulting order as `note_attributes`
     * [{name, value}].
     *
     * @param  array<string, mixed>  $orderPayload
     */
    private function attribute(array $orderPayload, string $name): ?string
    {
        foreach ((array) data_get($orderPayload, 'note_attributes', []) as $attr) {
            if (($attr['name'] ?? null) === $name) {
                return (string) ($attr['value'] ?? '');
            }
        }

        return null;
    }
}
