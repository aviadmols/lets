<?php

namespace App\Http\Controllers\WooCommerce\Account;

use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $active = $visitor->isIdentified() && $visitor->plans()
                ->where('plan_kind', PlanKind::RECURRING->value)
                ->whereIn('status', self::rewardedStatuses())
                ->exists();

            return response()->json(['ok' => true, 'active' => $active]);
        });
    }
}
