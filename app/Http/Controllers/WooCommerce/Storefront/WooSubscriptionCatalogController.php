<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Installments\ProductPriceResolver;
use App\Domain\Products\ProductPlanTemplateResolver;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The WooCommerce plugin's subscription catalog lookup (W17 Part B).
 *
 *   POST /api/woocommerce/subscriptions/flags  {product_ids:[]} → {id: bool}
 *        which of these products carry an ACTIVE subscription template (for the admin
 *        products-list marker + the storefront badge). Cheap; the plugin caches it.
 *
 *   POST /api/woocommerce/subscriptions/config {product_id, variant_id?} →
 *        {has_subscription, one_time_allowed, currency, subscription:{...}} — the resolved
 *        template config + the SERVER-computed per-cycle price for the product page.
 *
 * Money law: price_per_cycle is recomputed server-side from the synced catalog + the merchant
 * template (client sends only ids). Tenant law: $shop is the HMAC-verified shop; every query runs
 * under Tenant::run($shop) (BelongsToShop) so another shop's catalog/templates are invisible.
 */
final class WooSubscriptionCatalogController extends WooStorefrontController
{
    public function __construct(
        private readonly ProductPlanTemplateResolver $templates,
        private readonly ProductPriceResolver $prices,
    ) {}

    /** POST /subscriptions/flags — which product ids have an active subscription template. */
    public function flags(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => (string) $id,
            (array) $request->input('product_ids', []),
        ), static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            return response()->json(['ok' => true, 'flags' => []]);
        }

        $subscriptionIds = Tenant::run($shop, static fn (): array => Product::query()
            ->where('source', Product::SOURCE_WOOCOMMERCE)
            ->whereIn('external_id', $ids)
            ->whereHas('subscriptionPlans', static function ($q): void {
                $q->where('plan_type', ProductSubscriptionPlan::TYPE_SUBSCRIPTION)
                    ->where('status', ProductSubscriptionPlan::STATUS_ACTIVE);
            })
            ->pluck('external_id')
            ->map(static fn ($v): string => (string) $v)
            ->all());

        $flags = [];
        foreach ($ids as $id) {
            $flags[$id] = in_array($id, $subscriptionIds, true);
        }

        return response()->json(['ok' => true, 'flags' => $flags]);
    }

    /** POST /subscriptions/config — the resolved template + server per-cycle price for a product. */
    public function config(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $productId = (string) $request->input('product_id', '');
        $variantId = (string) $request->input('variant_id', $productId);
        $currency = (string) config('payplus.currency', 'ILS');

        return Tenant::run($shop, function () use ($shop, $productId, $variantId, $currency): JsonResponse {
            $subscription = $this->templates->resolveDefaultsFor($shop, $productId, $variantId);
            $oneTime = $this->templates->resolveActive($shop, $productId, $variantId, ProductSubscriptionPlan::TYPE_ONE_TIME);

            if ($subscription === null) {
                // No ACTIVE subscription plan. Distinguish "a plan exists but is still Draft" (the
                // merchant just needs to activate it) from "no plan at all" — so the plugin's
                // product-edit panel can give the precise remedy instead of "define one".
                return response()->json([
                    'ok' => true,
                    'has_subscription' => false,
                    'draft_subscription' => $this->templates->hasDraftSubscription($shop, $productId, $variantId),
                    'one_time_allowed' => true,
                    'subscription' => null,
                ]);
            }

            // Server-trusted per-cycle price from the synced catalog + the template discount.
            $resolved = $this->prices->resolve($productId, $variantId);
            $unitPrice = $resolved !== null ? round((float) $resolved['variant']->price, 2) : 0.0;
            $perCycle = $subscription->discountedPrice($unitPrice);

            return response()->json([
                'ok' => true,
                'has_subscription' => true,
                // When a product has ONLY a subscription template, it is subscription-only.
                'one_time_allowed' => $oneTime !== null,
                'currency' => $currency,
                'subscription' => [
                    'billing_frequency' => (string) ($subscription->billing_frequency?->value ?? 'monthly'),
                    'interval_count' => max(1, (int) $subscription->interval_count),
                    'discount_type' => (string) $subscription->discount_type,
                    'discount_value' => round((float) $subscription->discount_value, 2),
                    'base_price' => $unitPrice,
                    'price_per_cycle' => $perCycle,
                    'plan_name' => $subscription->plan_name,
                ],
            ]);
        });
    }
}
