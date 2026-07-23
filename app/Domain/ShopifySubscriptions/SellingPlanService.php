<?php

namespace App\Domain\ShopifySubscriptions;

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
    /** The merchant-facing group name shown on the product page's purchase options. */
    private const GROUP_NAME = 'Subscribe & save';
    private const GROUP_OPTION = 'Delivery every';

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
