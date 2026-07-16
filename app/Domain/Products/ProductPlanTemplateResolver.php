<?php

namespace App\Domain\Products;

use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Support\Tenant;

/**
 * Resolves the merchant-configured subscription plan TEMPLATE for a purchased
 * product/variant. This is the ACTIVATION SEAM between the Products screen
 * (templates) and the charge engine (per-customer InstallmentPlan) — it reads
 * templates only; it NEVER touches the ledger, ChargeOrchestrator, or money math.
 *
 * The seam (documented so the future listener wires in cleanly):
 *   The `shopify.order.paid` listener that builds a customer InstallmentPlan calls
 *   resolveDefaultsFor(shop, productGid, variantGid) BEFORE applying defaults, and
 *   inherits billing_frequency / interval_count / discount from the returned
 *   template ONLY where the checkout intent did not already specify them.
 *
 *   Precedence (highest wins):
 *     1. explicit checkout intent (what the customer actually selected)
 *     2. this template default (the merchant's per-variant config)
 *     3. engine fallback (BillingFrequency::addTo + existing amount math)
 *
 * Returns null when no matching active subscription template exists — the caller
 * then proceeds on checkout intent + engine fallback alone (no behaviour change).
 *
 * Tenant-safe: every lookup runs through the BelongsToShop global scope bound to
 * the passed Shop, so a product/variant from another shop is invisible (fails
 * closed) — never withoutGlobalScope.
 */
final class ProductPlanTemplateResolver
{
    /**
     * Find the active SUBSCRIPTION template a new customer plan should inherit
     * from for the purchased product (and variant, if known). Variant-specific
     * template wins; else the product-wide (null-variant) template; else null.
     *
     * @param string      $productGid the upstream product reference — a Shopify GID
     *                                 (gid://shopify/Product/123) or a bare numeric/external id
     * @param string|null $variantGid the upstream variant reference (GID or bare id), if known
     */
    public function resolveDefaultsFor(Shop $shop, string $productGid, ?string $variantGid = null): ?ProductSubscriptionPlan
    {
        return $this->resolveActive($shop, $productGid, $variantGid, ProductSubscriptionPlan::TYPE_SUBSCRIPTION);
    }

    /**
     * The active template of a given plan_type (subscription | one_time) for a product/variant.
     * Variant-specific wins over product-wide; null when none. Tenant-safe (BelongsToShop). Used by
     * the WooCommerce subscription catalog endpoint to answer "is this a subscription?" and "is a
     * one-time purchase also offered?".
     */
    public function resolveActive(Shop $shop, string $productGid, ?string $variantGid, string $planType): ?ProductSubscriptionPlan
    {
        return Tenant::run($shop, function () use ($shop, $productGid, $variantGid, $planType): ?ProductSubscriptionPlan {
            $externalId = $this->externalId($productGid);
            if ($externalId === '') {
                return null;
            }

            // Tenant-scoped product lookup (BelongsToShop bound to $shop). Match by
            // source so a future WooCommerce shop never collides with a Shopify id.
            $product = Product::query()
                ->where('source', $shop->platform ?? Product::SOURCE_SHOPIFY)
                ->where('external_id', $externalId)
                ->first();

            if ($product === null) {
                return null;
            }

            $variantId = null;
            if ($variantGid !== null && $variantGid !== '') {
                $variantExternalId = $this->externalId($variantGid);
                $variantId = $product->variants()
                    ->where('external_variant_id', $variantExternalId)
                    ->value('id');
            }

            // Variant-specific template first (most specific), then the product-wide
            // (null-variant) template. Only ACTIVE templates of the requested type qualify.
            if ($variantId !== null) {
                $variantSpecific = $this->activeTemplateQuery($product, $planType)
                    ->where('product_variant_id', $variantId)
                    ->first();

                if ($variantSpecific !== null) {
                    return $variantSpecific;
                }
            }

            return $this->activeTemplateQuery($product, $planType)
                ->whereNull('product_variant_id')
                ->first();
        });
    }

    /**
     * Does a DRAFT subscription template exist for this product/variant? A draft plan is invisible
     * to the storefront + /flags + /config (only ACTIVE templates qualify), so a merchant who
     * defined a plan but never activated it sees "no subscription detected" and is confused. This
     * lets the caller say precisely "you have a plan — activate it" instead. Tenant-safe
     * (BelongsToShop bound to $shop); variant-specific OR product-wide draft both count.
     */
    public function hasDraftSubscription(Shop $shop, string $productGid, ?string $variantGid = null): bool
    {
        return Tenant::run($shop, function () use ($shop, $productGid, $variantGid): bool {
            $externalId = $this->externalId($productGid);
            if ($externalId === '') {
                return false;
            }

            $product = Product::query()
                ->where('source', $shop->platform ?? Product::SOURCE_SHOPIFY)
                ->where('external_id', $externalId)
                ->first();

            if ($product === null) {
                return false;
            }

            $draftQuery = $product->subscriptionPlans()
                ->where('plan_type', ProductSubscriptionPlan::TYPE_SUBSCRIPTION)
                ->where('status', ProductSubscriptionPlan::STATUS_DRAFT);

            if ($variantGid !== null && $variantGid !== '') {
                $variantId = $product->variants()
                    ->where('external_variant_id', $this->externalId($variantGid))
                    ->value('id');
                if ($variantId !== null) {
                    // A draft on this variant OR a product-wide (null-variant) draft.
                    $draftQuery->where(static function ($q) use ($variantId): void {
                        $q->where('product_variant_id', $variantId)->orWhereNull('product_variant_id');
                    });
                }
            }

            return $draftQuery->exists();
        });
    }

    /** Active templates of a plan_type for a product, lowest position first. */
    private function activeTemplateQuery(Product $product, string $planType)
    {
        return $product->subscriptionPlans()
            ->where('plan_type', $planType)
            ->where('status', ProductSubscriptionPlan::STATUS_ACTIVE)
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Normalize an upstream reference to the bare external id we cache. Accepts a
     * Shopify GID (gid://shopify/Product/123 → "123") or an already-bare id. The
     * trailing-digits rule matches UpsellFlowOffer::productNumericId.
     */
    private function externalId(string $reference): string
    {
        $reference = trim($reference);

        if (str_contains($reference, 'gid://') && preg_match('/(\d+)$/', $reference, $m) === 1) {
            return $m[1];
        }

        return $reference;
    }
}
