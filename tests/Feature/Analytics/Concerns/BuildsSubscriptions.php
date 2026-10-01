<?php

namespace Tests\Feature\Analytics\Concerns;

use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Carbon\CarbonImmutable;

/**
 * Fixtures for the Analytics tests: shops, recurring plans, Shopify contracts
 * and the status_changed Timeline rows the movement log reads. Every helper
 * writes under the shop it is given (Tenant::run), so isolation tests can
 * build two shops side by side.
 */
trait BuildsSubscriptions
{
    // === CONSTANTS ===
    protected const SHOP_DOMAIN = 'analytics-%s.myshopify.com';

    protected function makeShop(string $name = 'a'): Shop
    {
        return Shop::create([
            'shopify_domain' => sprintf(self::SHOP_DOMAIN, $name),
            'name' => 'Analytics '.$name,
            'status' => Shop::STATUS_ACTIVE,
        ]);
    }

    /** A recurring plan in $status, created at $createdAt. */
    protected function plan(
        Shop $shop,
        string $customer,
        float $amount = 50,
        string $frequency = 'monthly',
        int $intervalCount = 1,
        string $status = 'active',
        ?CarbonImmutable $createdAt = null,
        array $attributes = [],
    ): InstallmentPlan {
        return Tenant::run($shop, function () use ($customer, $amount, $frequency, $intervalCount, $status, $createdAt, $attributes): InstallmentPlan {
            $plan = InstallmentPlan::create(array_merge([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'shopify_customer_id' => $customer,
                'total_amount' => $amount,
                'total_charged' => 0,
                'installment_amount' => $amount,
                'billing_frequency' => $frequency,
                'interval_count' => $intervalCount,
                'currency' => 'ILS',
                'public_id' => 'PLAN-'.uniqid('', true),
            ], $attributes));
            $plan->forceFill(['status' => $status])->save();
            if ($createdAt !== null) {
                $plan->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
            }

            return $plan->fresh();
        });
    }

    /** A status move on the Timeline, exactly as HasGuardedStatus writes it. */
    protected function move(InstallmentPlan $plan, string $from, string $to, CarbonImmutable $at, string $actor = 'system'): void
    {
        $event = new ActivityEvent();
        $event->forceFill([
            'shop_id' => $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'payment_id' => null,
            'actor' => $actor,
            'kind' => 'status_changed',
            'details' => ['model' => 'InstallmentPlan', 'from' => $from, 'to' => $to],
            'created_at' => $at,
        ])->save();
    }

    protected function contract(Shop $shop, string $customer, float $amount = 60, string $suffix = 'a', string $interval = 'MONTH', ?CarbonImmutable $createdAt = null): SubscriptionContract
    {
        $contract = new SubscriptionContract();
        $contract->forceFill([
            'shop_id' => $shop->getKey(),
            'shopify_gid' => 'gid://shopify/SubscriptionContract/'.$customer.$suffix,
            'shopify_customer_gid' => 'gid://shopify/Customer/'.$customer,
            'status' => SubscriptionContract::STATUS_ACTIVE,
            'interval' => $interval,
            'interval_count' => 1,
            'next_billing_date' => now()->addMonth(),
            'amount' => $amount,
            'currency' => 'ILS',
            'synced_at' => now(),
        ])->save();
        if ($createdAt !== null) {
            $contract->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $contract;
    }

    protected function statusOf(string $status): string
    {
        return PlanStatus::from($status)->value;
    }
}
