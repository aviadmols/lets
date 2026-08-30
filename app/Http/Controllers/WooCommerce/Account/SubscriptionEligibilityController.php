<?php

namespace App\Http\Controllers\WooCommerce\Account;

use App\Models\MerchantBillingSettings;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/account/subscription-eligibility
 *
 * "May this shopper start ANOTHER subscription?" — asked by the storefront before
 * a subscription line reaches the cart, and again at checkout when a guest finally
 * types the email that identifies them.
 *
 * The answer is computed here rather than in the plugin because the rule is the
 * merchant's and the data is ours: the plugin knows who is buying, we know what
 * they already hold. The refusal text comes back already translated, for the same
 * reason the whole account payload does — the plugin ships no catalogs.
 *
 * A shop that never turned the rule on is answered `blocked: false` without a
 * lookup, so the endpoint stays cheap for the majority that allow many plans.
 */
final class SubscriptionEligibilityController extends WooAccountController
{
    // === CONSTANTS ===
    /**
     * What counts as "already has a subscription".
     *
     * ACTIVE and PAUSED are plainly live. FAILED is too — a card that stopped
     * working is a subscription in dunning, and the answer to it is to fix the
     * card in the account area, not to buy a second plan beside it.
     *
     * AWAITING_FIRST_PAYMENT is deliberately NOT here. That row is what an
     * abandoned checkout leaves behind, and treating it as a subscription would
     * lock a shopper out of the shop over a payment they never completed — the
     * exact opposite of what this rule is for.
     */
    private static function liveStatuses(): array
    {
        // One source of truth (PlanStatus::live()), so a status added to the
        // lifecycle can never be forgotten here — this list decides whether a
        // paying subscriber is offered a SECOND subscription beside the one
        // they already have.
        return PlanStatus::live();
    }

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return Tenant::run($shop, function () use ($request, $shop): JsonResponse {
            if (! MerchantBillingSettings::current()->allowsOneSubscriptionOnly()) {
                return response()->json(['ok' => true, 'single_only' => false, 'blocked' => false]);
            }

            $visitor = $this->visitor($request, $shop);

            // An unidentified caller is not blocked. We cannot prove they hold
            // anything, and refusing a sale on a guess is the worse error: the
            // checkout hook asks again once the billing email is known.
            $blocked = $visitor->isIdentified() && $visitor->plans()
                ->whereIn('status', array_map(static fn (PlanStatus $s): string => $s->value, self::liveStatuses()))
                ->exists();

            return response()->json($this->inShopperLocale(static fn (): array => [
                'ok' => true,
                'single_only' => true,
                'blocked' => $blocked,
                'message' => $blocked ? __('account.purchase.blocked') : null,
                'link_label' => $blocked ? __('account.purchase.blocked_link') : null,
            ], $request));
        });
    }
}
