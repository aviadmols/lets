<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Installments\DepositPlanService;
use App\Domain\Installments\ProductPriceResolver;
use App\Domain\Installments\RecurringPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/installments/subscribe
 *
 * Creates an open-ended RECURRING subscription plan (plan_kind=recurring, no deposit, no
 * finite slices) + the PayPlus FIRST-PAYMENT page, then returns the page URL the plugin
 * redirects the browser to. On payment the SAME deposit callback activates the plan (sets
 * next_charge_at to the next cycle) and the recurring engine bills every cycle thereafter.
 *
 * Money law: the per-cycle price is resolved SERVER-SIDE from the synced WC catalog cache
 * (the client sends only the variation id + cadence knobs, never an amount). Tenant law:
 * $shop is the HMAC-verified shop only; RecurringPlanService stamps shop_id from it. No
 * charge happens here — the first cycle is collected on the returned PayPlus page.
 */
final class WooSubscribeController extends WooStorefrontController
{
    public function __construct(
        private readonly ProductPriceResolver $prices,
        private readonly RecurringPlanService $plans,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $productId = (string) $request->input('product_id', $request->input('product_gid', ''));
        $variantId = (string) $request->input('variant_id', $request->input('variant_gid', ''));

        $resolved = $this->prices->resolve($productId, $variantId);
        if ($resolved === null) {
            return response()->json(['error' => 'variant_not_priceable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Server-trusted per-cycle amount (the recurring price), never a client value.
        $amount = round((float) $resolved['variant']->price, 2);

        try {
            $result = $this->plans->create($shop, [
                'product_gid' => $productId,
                'variant_gid' => $variantId,
                'item_title' => $resolved['title'],
                'amount' => $amount,
                // The cadence is a bounded knob; frequencyFrom clamps to an allowed value.
                'frequency' => DepositPlanService::frequencyFrom($request->input('frequency')),
                'interval_count' => max(1, (int) $request->input('interval_count', 1)),
                'currency' => (string) ($request->input('currency') ?: config('payplus.currency', 'ILS')),
                'customer_email' => $this->cleanEmail($request->input('customer_email')),
                'customer_name' => $this->cleanString($request->input('customer_name')),
                'customer_phone' => $this->cleanString($request->input('customer_phone')),
            ]);
        } catch (\Throwable $e) {
            Log::error('woocommerce.subscribe.start_failed', [
                'shop_id' => $shop->getKey(),
                'variant_id' => $variantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'plan_creation_failed'], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'plan_public_id' => $result['plan']->public_id,
            'invoice_url' => $result['invoice_url'],
            'amount' => $amount,
            'currency' => (string) $result['plan']->currency,
        ], Response::HTTP_CREATED);
    }
}
