<?php

namespace App\Domain\ShopifySubscriptions;

use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Creates the selling plan group that makes products SUBSCRIBABLE at checkout —
 * the entry point of the whole Shopify-Payments rail. A shopper who picks the
 * plan at checkout produces a SubscriptionContract that OUR app owns
 * (`write_own_subscription_contracts` = contracts created via our selling plans),
 * which is the only reason the personal area can read it at all.
 *
 * The group is created once per shop and products are attached to it; cadence
 * options (e.g. every 1/2/3 months) are selling plans inside the group. Billing
 * from then on is app-driven: Shopify vaults the card and processes payments, but
 * the app schedules each cycle (DueBillingCycleScanner → BillingAttemptJob).
 */
final class SellingPlanService
{
    // === CONSTANTS ===
    /** The SHOPPER-facing group name (the purchase-options heading at checkout). */
    private const GROUP_NAME = 'Subscribe & save';
    private const GROUP_OPTION = 'Delivery every';

    /**
     * The MERCHANT-facing label. Shopify prints `merchantCode` as the title of
     * each row in the product's "Purchase options" card, so it has to read like
     * a name a human chose — not the internal id it started as.
     */
    private const MERCHANT_LABEL = "Let's subscription";

    /**
     * The marks written onto the Shopify PRODUCT when a plan is published, so a
     * merchant looking at the product in the SHOPIFY admin can see it is sold as
     * a subscription without opening this app: a tag (visible + filterable in the
     * Organization card and the product list) and a metafield carrying the plan.
     */
    private const PRODUCT_TAG = 'LETS Subscription';
    private const METAFIELD_KEY = 'subscription_plan';

    /** Shopify's recurring intervals, keyed by our BillingFrequency values. */
    private const INTERVAL_MAP = [
        'daily' => ['DAY', 1],
        'weekly' => ['WEEK', 1],
        'biweekly' => ['WEEK', 2],
        'monthly' => ['MONTH', 1],
        'quarterly' => ['MONTH', 3],
        'yearly' => ['YEAR', 1],
    ];

    /**
     * Publish ONE merchant plan template to Shopify as a selling plan, so the
     * product becomes subscribable at checkout and the resulting contract is one
     * OUR app owns. Idempotent per template: an already-published template is
     * re-pointed at a fresh group rather than duplicated, because a template
     * with two live selling plans would offer the shopper the same plan twice.
     *
     * Stores the group + plan gids on the template — their presence is what the
     * admin reads as "live", and what the storefront block renders from.
     *
     * @return array{group_gid: string, plan_gid: string}
     */
    public function publishTemplate(Shop $shop, ProductSubscriptionPlan $template): array
    {
        if (! $template->isSubscription()) {
            throw new RuntimeException('shopify_subscriptions.selling_plan_needs_subscription_template');
        }

        $product = $template->product;
        if (! $product instanceof Product || (string) $product->external_id === '') {
            throw new RuntimeException('shopify_subscriptions.selling_plan_needs_product');
        }

        [$interval, $unitCount] = self::INTERVAL_MAP[(string) ($template->billing_frequency?->value ?? 'monthly')]
            ?? self::INTERVAL_MAP['monthly'];
        $intervalCount = max(1, (int) ($template->interval_count ?: 1)) * $unitCount;

        $plan = [
            'name' => (string) ($template->plan_name ?: self::GROUP_NAME),
            'options' => [$this->optionLabel($interval, $intervalCount)],
            'category' => 'SUBSCRIPTION',
            'billingPolicy' => ['recurring' => ['interval' => $interval, 'intervalCount' => $intervalCount]],
            'deliveryPolicy' => ['recurring' => ['interval' => $interval, 'intervalCount' => $intervalCount]],
        ];

        // The merchant's subscribe-and-save incentive, applied by Shopify at
        // checkout — the discount must live WHERE the charge happens, so on this
        // rail Shopify owns it (the PayPlus rail applies it in our own math).
        $pricing = $this->pricingPolicy($template);
        if ($pricing !== null) {
            $plan['pricingPolicies'] = [$pricing];
        }

        $body = ShopifyClientFactory::for($shop)->graphql(<<<'GQL'
        mutation sellingPlanGroupCreate($input: SellingPlanGroupInput!, $resources: SellingPlanGroupResourceInput) {
          sellingPlanGroupCreate(input: $input, resources: $resources) {
            sellingPlanGroup { id sellingPlans(first: 1) { edges { node { id } } } }
            userErrors { field message }
          }
        }
        GQL, [
            'input' => [
                'name' => self::GROUP_NAME,
                'merchantCode' => $this->merchantCode($template),
                'options' => [self::GROUP_OPTION],
                'sellingPlansToCreate' => [$plan],
            ],
            'resources' => ['productIds' => [$this->productGid((string) $product->external_id)]],
        ]);

        $payload = (array) data_get($body, 'data.sellingPlanGroupCreate', []);
        $errors = (array) ($payload['userErrors'] ?? []);

        if ($errors !== []) {
            Log::warning('shopify_subscriptions.selling_plan_group_rejected', [
                'shop_id' => $shop->getKey(), 'template_id' => $template->getKey(), 'errors' => $errors,
            ]);
            throw new RuntimeException('shopify_subscriptions.selling_plan_group_rejected: '
                .json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        $groupGid = (string) data_get($payload, 'sellingPlanGroup.id', '');
        $planGid = (string) data_get($payload, 'sellingPlanGroup.sellingPlans.edges.0.node.id', '');
        if ($groupGid === '' || $planGid === '') {
            throw new RuntimeException('shopify_subscriptions.selling_plan_group_no_gid');
        }

        // Retire the template's PREVIOUS group (if any) only after the new one
        // exists — a failed create must never leave the product unsubscribable.
        $previous = (string) ($template->shopify_selling_plan_group_gid ?? '');
        if ($previous !== '' && $previous !== $groupGid) {
            $this->deleteGroup($shop, $previous);
        }

        $template->forceFill([
            'shopify_selling_plan_group_gid' => $groupGid,
            'shopify_selling_plan_gid' => $planGid,
            'shopify_synced_at' => now(),
        ])->save();

        // Cosmetic, so it must never break the money-critical part: the selling
        // plan is already live, and a failed tag write is logged, not thrown.
        $this->markProduct($shop, $product, $plan['name'], $this->optionLabel($interval, $intervalCount));

        return ['group_gid' => $groupGid, 'plan_gid' => $planGid];
    }

    /**
     * Remove a template's selling plan from Shopify — the product stops offering
     * it at checkout. EXISTING CONTRACTS ARE UNAFFECTED: they live at Shopify
     * independently of the plan that created them.
     */
    public function unpublishTemplate(Shop $shop, ProductSubscriptionPlan $template): void
    {
        $groupGid = (string) ($template->shopify_selling_plan_group_gid ?? '');
        if ($groupGid !== '') {
            $this->deleteGroup($shop, $groupGid);
        }

        $template->forceFill([
            'shopify_selling_plan_group_gid' => null,
            'shopify_selling_plan_gid' => null,
            'shopify_synced_at' => null,
        ])->save();

        // Clear the product's marks only when NO other template of this product is
        // still published — otherwise the tag would lie about the remaining plans.
        $product = $template->product;
        if ($product instanceof Product && ! $this->hasOtherPublishedTemplate($template)) {
            $this->unmarkProduct($shop, $product);
        }
    }

    /** Does another template of the same product still have a live selling plan? */
    private function hasOtherPublishedTemplate(ProductSubscriptionPlan $template): bool
    {
        return ProductSubscriptionPlan::query()
            ->where('product_id', $template->product_id)
            ->whereKeyNot($template->getKey())
            ->whereNotNull('shopify_selling_plan_gid')
            ->exists();
    }

    /**
     * Tag + metafield the Shopify product so the subscription is visible IN THE
     * SHOPIFY ADMIN (Organization → Tags, and the product-metafields card), not
     * only inside this app. Best-effort: a failure here leaves a live selling
     * plan with no tag, which is cosmetic — never the reverse.
     */
    private function markProduct(Shop $shop, Product $product, string $planName, string $cadence): void
    {
        $gid = $this->productGid((string) $product->external_id);

        try {
            $client = ShopifyClientFactory::for($shop);

            $client->graphql(<<<'GQL'
            mutation letsTagAdd($id: ID!, $tags: [String!]!) {
              tagsAdd(id: $id, tags: $tags) { userErrors { field message } }
            }
            GQL, ['id' => $gid, 'tags' => [self::PRODUCT_TAG]]);

            $client->graphql(<<<'GQL'
            mutation letsMarkProduct($metafields: [MetafieldsSetInput!]!) {
              metafieldsSet(metafields: $metafields) { userErrors { field message } }
            }
            GQL, ['metafields' => [[
                'ownerId' => $gid,
                'namespace' => (string) config('shopify.metafield_namespace'),
                'key' => self::METAFIELD_KEY,
                'type' => 'single_line_text_field',
                'value' => mb_substr(trim($planName.' · '.$cadence), 0, 250),
            ]]]);
        } catch (\Throwable $e) {
            Log::warning('shopify_subscriptions.product_mark_failed', [
                'shop_id' => $shop->getKey(), 'product_id' => $product->getKey(), 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Remove the marks — the product no longer sells as a subscription. */
    private function unmarkProduct(Shop $shop, Product $product): void
    {
        $gid = $this->productGid((string) $product->external_id);

        try {
            $client = ShopifyClientFactory::for($shop);

            $client->graphql(<<<'GQL'
            mutation letsTagRemove($id: ID!, $tags: [String!]!) {
              tagsRemove(id: $id, tags: $tags) { userErrors { field message } }
            }
            GQL, ['id' => $gid, 'tags' => [self::PRODUCT_TAG]]);

            $client->graphql(<<<'GQL'
            mutation letsUnmarkProduct($metafields: [MetafieldIdentifierInput!]!) {
              metafieldsDelete(metafields: $metafields) { userErrors { field message } }
            }
            GQL, ['metafields' => [[
                'ownerId' => $gid,
                'namespace' => (string) config('shopify.metafield_namespace'),
                'key' => self::METAFIELD_KEY,
            ]]]);
        } catch (\Throwable $e) {
            Log::warning('shopify_subscriptions.product_unmark_failed', [
                'shop_id' => $shop->getKey(), 'product_id' => $product->getKey(), 'error' => $e->getMessage(),
            ]);
        }
    }

    private function deleteGroup(Shop $shop, string $groupGid): void
    {
        try {
            ShopifyClientFactory::for($shop)->graphql(<<<'GQL'
            mutation sellingPlanGroupDelete($id: ID!) {
              sellingPlanGroupDelete(id: $id) { deletedSellingPlanGroupId userErrors { field message } }
            }
            GQL, ['id' => $groupGid]);
        } catch (\Throwable $e) {
            // A group that cannot be deleted (already gone, transient failure) must
            // not block the caller — the local link is the thing that matters here.
            Log::warning('shopify_subscriptions.selling_plan_group_delete_failed', [
                'shop_id' => $shop->getKey(), 'group_gid' => $groupGid, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed>|null the fixed pricing policy, or null for no discount */
    private function pricingPolicy(ProductSubscriptionPlan $template): ?array
    {
        $value = round((float) $template->discount_value, 2);
        if ($value <= 0) {
            return null;
        }

        return match ($template->discount_type) {
            ProductSubscriptionPlan::DISCOUNT_PERCENT => ['fixed' => [
                'adjustmentType' => 'PERCENTAGE',
                'adjustmentValue' => ['percentage' => min($value, 100)],
            ]],
            ProductSubscriptionPlan::DISCOUNT_FIXED => ['fixed' => [
                'adjustmentType' => 'FIXED_AMOUNT',
                'adjustmentValue' => ['fixedValue' => $value],
            ]],
            default => null,
        };
    }

    /**
     * The row title a merchant reads in Shopify's "Purchase options" card:
     * the brand plus the plan's own name, so a product carrying two plans shows
     * which is which instead of two identical rows.
     */
    private function merchantCode(ProductSubscriptionPlan $template): string
    {
        $name = trim((string) ($template->plan_name ?? ''));

        return $name === '' || $name === self::MERCHANT_LABEL
            ? self::MERCHANT_LABEL
            : mb_substr(self::MERCHANT_LABEL.' — '.$name, 0, 100);
    }

    /** "5001" or an already-canonical gid → the canonical product GID. */
    private function productGid(string $externalId): string
    {
        return str_starts_with($externalId, 'gid://')
            ? $externalId
            : 'gid://shopify/Product/'.$externalId;
    }

    /** The shopper-facing option label, e.g. "1 month" / "2 weeks". */
    private function optionLabel(string $interval, int $count): string
    {
        $unit = strtolower($interval);

        return $count === 1 ? '1 '.$unit : $count.' '.$unit.'s';
    }

    /**
     * Create a selling plan group offering the given monthly cadences, attached to
     * the given products. Returns the group GID.
     *
     * @param  list<int>  $monthlyIntervals  e.g. [1, 2, 3] = every 1/2/3 months
     * @param  list<string>  $productGids
     */
    public function createGroup(Shop $shop, array $monthlyIntervals, array $productGids, ?float $percentageOff = null): string
    {
        $plans = array_map(static function (int $months) use ($percentageOff): array {
            $plan = [
                'name' => $months === 1 ? 'Every month' : "Every {$months} months",
                'options' => [$months === 1 ? '1 month' : "{$months} months"],
                'category' => 'SUBSCRIPTION',
                'billingPolicy' => ['recurring' => ['interval' => 'MONTH', 'intervalCount' => $months]],
                'deliveryPolicy' => ['recurring' => ['interval' => 'MONTH', 'intervalCount' => $months]],
            ];

            // Optional subscribe-and-save incentive, applied by Shopify at checkout.
            if ($percentageOff !== null && $percentageOff > 0) {
                $plan['pricingPolicies'] = [[
                    'fixed' => [
                        'adjustmentType' => 'PERCENTAGE',
                        'adjustmentValue' => ['percentage' => round($percentageOff, 2)],
                    ],
                ]];
            }

            return $plan;
        }, array_values(array_unique(array_map(
            static fn ($m): int => max(1, (int) $m),
            $monthlyIntervals,
        ))));

        if ($plans === [] || $productGids === []) {
            throw new RuntimeException('shopify_subscriptions.selling_plan_group_needs_plans_and_products');
        }

        $body = ShopifyClientFactory::for($shop)->graphql(<<<'GQL'
        mutation sellingPlanGroupCreate($input: SellingPlanGroupInput!, $resources: SellingPlanGroupResourceInput) {
          sellingPlanGroupCreate(input: $input, resources: $resources) {
            sellingPlanGroup { id }
            userErrors { field message }
          }
        }
        GQL, [
            'input' => [
                'name' => self::GROUP_NAME,
                'merchantCode' => 'lets-subscriptions',
                'options' => [self::GROUP_OPTION],
                'sellingPlansToCreate' => $plans,
            ],
            'resources' => ['productIds' => array_values($productGids)],
        ]);

        $payload = (array) data_get($body, 'data.sellingPlanGroupCreate', []);
        $errors = (array) ($payload['userErrors'] ?? []);

        if ($errors !== []) {
            Log::warning('shopify_subscriptions.selling_plan_group_rejected', [
                'shop_id' => $shop->getKey(), 'errors' => $errors,
            ]);
            throw new RuntimeException('shopify_subscriptions.selling_plan_group_rejected: '
                .json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        $gid = (string) data_get($payload, 'sellingPlanGroup.id', '');
        if ($gid === '') {
            throw new RuntimeException('shopify_subscriptions.selling_plan_group_no_gid');
        }

        return $gid;
    }
}
