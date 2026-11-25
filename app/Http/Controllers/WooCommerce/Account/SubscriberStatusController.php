<?php

namespace App\Http\Controllers\WooCommerce\Account;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/account/subscriber-status
 *
 * "Is this shopper a subscriber RIGHT NOW?" — asked by the plugin's checkout so a
 * merchant can give subscribers a discount on chosen products. The discount itself
 * (which products, how much) is WooCommerce's: it lives in the plugin and lands on
 * the order as a cart fee, and the gateway charges the order total it reports. We
 * only answer the one question the store cannot answer alone.
 *
 * Narrower than SubscriptionEligibilityController on purpose. That rule asks "does
 * this person still HOLD a plan" and must count dunning and pause, or a second plan
 * slips in beside the first. This one hands out money off an order, so it counts
 * only a subscription that is paid and running: a recurring plan, ACTIVE, or paid
 * and waiting for its activation link. Paused, dunning and failed plans are still
 * subscriptions, but not ones the merchant meant to reward. Installment plans are
 * a purchase being paid off, not a subscription, and never count.
 *
 * `product_ids` lists the store products (and variations) those same plans were
 * sold as, so the store can reward subscribers of chosen plans only. Additive:
 * an older plugin reads `active` and ignores it.
 */
final class SubscriberStatusController extends WooAccountController
{
    // === CONSTANTS ===
    /** @return list<string> */
    private static function rewardedStatuses(): array
    {
        return [PlanStatus::ACTIVE->value, PlanStatus::AWAITING_ACTIVATION->value];
    }

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return Tenant::run($shop, function () use ($request, $shop): JsonResponse {
            $visitor = $this->visitor($request, $shop);

            // An unidentified visitor matches nothing (AccountVisitor's 1 = 0
            // floor), so a lost reference can never discount a stranger's cart.
            $plans = $visitor->isIdentified()
                ? $visitor->plans()
                    ->where('plan_kind', PlanKind::RECURRING->value)
                    ->whereIn('status', self::rewardedStatuses())
                    ->get(['external_product_id', 'external_variant_id'])
                : collect();

            return response()->json([
                'ok' => true,
                'active' => $plans->isNotEmpty(),
                'product_ids' => self::productIds($plans),
            ]);
        });
    }

    /**
     * The store's product ids the rewarded plans were sold as: the product and,
     * for a variable product, the variation. Lets the store limit a reward to
     * subscribers of chosen plans (a coupon for the yearly plan only, say).
     *
     * @param  Collection<int, InstallmentPlan>  $plans
     * @return list<int>
     */
    private static function productIds(Collection $plans): array
    {
        return $plans
            ->flatMap(fn (InstallmentPlan $plan): array => [$plan->external_product_id, $plan->external_variant_id])
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter(fn (string $id): bool => $id !== '' && ctype_digit($id) && $id !== '0')
            ->map(fn (string $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
