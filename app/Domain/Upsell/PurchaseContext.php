<?php

namespace App\Domain\Upsell;

use App\Models\InstallmentPlan;

/**
 * The source purchase a thank-you-page upsell is evaluated against. Carries the
 * exact facts a trigger needs: which products were bought (gids), which
 * collections / tags they belong to, the order subtotal, the parent order id,
 * and the customer reference (for the deterministic idempotency key + consent).
 *
 * Decouples the resolver from Shopify: the shopify-integration layer builds this
 * from a real order; tests build it inline. NOT a model — a request-scoped DTO.
 */
final class PurchaseContext
{
    /**
     * @param list<string> $purchasedProductGids
     * @param list<string> $purchasedCollectionGids
     * @param list<string> $purchasedTags
     */
    public function __construct(
        public readonly int $shopId,
        public readonly string $parentOrderId,
        public readonly string $customerRef,
        public readonly float $orderSubtotal,
        public readonly array $purchasedProductGids = [],
        public readonly array $purchasedCollectionGids = [],
        public readonly array $purchasedTags = [],
        public readonly ?int $planId = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $currency = null,
        public readonly ?int $paymentMethodId = null,
        public readonly ?int $customerId = null,
        public readonly ?string $shopifyCustomerId = null,
    ) {}

    /**
     * Build from an installments/recurring plan whose first charge just landed
     * (the plan IS the source purchase). The shopify-integration layer enriches
     * collections/tags; here we carry what the plan already knows.
     */
    public static function fromPlan(InstallmentPlan $plan, float $orderSubtotal): self
    {
        // The plan stores the numeric product id; normalise to a gid so triggers
        // can match either form the merchant configured.
        $productId = (string) ($plan->shopify_product_id ?? '');
        $productGids = array_values(array_filter([
            $productId !== '' && ! str_starts_with($productId, 'gid://')
                ? 'gid://shopify/Product/'.$productId
                : $productId,
            $productId,
        ], static fn (string $v): bool => $v !== ''));

        return new self(
            shopId: (int) $plan->shop_id,
            parentOrderId: (string) ($plan->shopify_order_id ?? ''),
            customerRef: (string) ($plan->shopify_customer_id ?? $plan->customer_id ?? ''),
            orderSubtotal: round($orderSubtotal, 2),
            purchasedProductGids: $productGids,
            planId: (int) $plan->getKey(),
            customerEmail: $plan->customer_email,
            currency: $plan->currency,
            paymentMethodId: $plan->payment_method_id ? (int) $plan->payment_method_id : null,
            customerId: $plan->customer_id ? (int) $plan->customer_id : null,
            shopifyCustomerId: $plan->shopify_customer_id,
        );
    }
}
