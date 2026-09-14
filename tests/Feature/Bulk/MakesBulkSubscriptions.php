<?php

namespace Tests\Feature\Bulk;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;

/**
 * Fixtures for the bulk-edit suite.
 *
 * The shapes here are the ones a bulk edit has to survive: a plan already sitting
 * on the value being set (which must be skipped, not written), a CANCELLED plan
 * inside the filter (which must never be given a charge date), an instalment plan
 * beside a recurring one (which must not be re-cadenced), and — for the scale
 * tests — a few thousand rows written straight through the query builder, because
 * a thousand Eloquent saves is a test nobody waits for.
 */
trait MakesBulkSubscriptions
{
    // === CONSTANTS ===
    protected const PRODUCT_COFFEE = 'prod-coffee';

    protected const PRODUCT_TEA = 'prod-tea';

    protected function makeShop(string $domain = 'bulk.example.com'): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => 'Bulk '.$domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    /**
     * One subscription, with only the columns a bulk edit reads.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makePlan(Shop $shop, string $ref, array $overrides = []): InstallmentPlan
    {
        $plan = new InstallmentPlan;

        $plan->forceFill(array_merge([
            'shop_id' => $shop->getKey(),
            'public_id' => 'PLN-'.$ref,
            'customer_name' => $ref,
            'customer_email' => $ref.'@example.com',
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 50,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'external_product_id' => self::PRODUCT_COFFEE,
            'next_charge_at' => now()->addDays(10)->startOfDay(),
        ], $overrides))->save();

        return $plan;
    }

    /**
     * Many subscriptions at once, through the query builder.
     *
     * Deliberately NOT a loop of makePlan(): the scale tests need thousands of
     * rows, and the point of those tests is the cost of the RUN, which a slow
     * fixture would bury.
     *
     * @return list<int> the ids created, in ascending order
     */
    protected function makePlans(Shop $shop, int $count, ?string $nextChargeAt = null): array
    {
        $now = now();
        $date = $nextChargeAt ?? $now->copy()->addDays(10)->startOfDay()->toDateTimeString();

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'shop_id' => $shop->getKey(),
                'public_id' => 'PLN-bulk-'.$shop->getKey().'-'.$i,
                'customer_name' => 'Member '.$i,
                'customer_email' => 'member'.$i.'@example.com',
                'plan_kind' => PlanKind::RECURRING->value,
                'status' => PlanStatus::ACTIVE->value,
                'total_amount' => 0,
                'total_charged' => 0,
                'installment_amount' => 50,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'external_product_id' => self::PRODUCT_COFFEE,
                'next_charge_at' => $date,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            InstallmentPlan::query()->insert($chunk);
        }

        return InstallmentPlan::query()->orderBy('id')->pluck('id')->all();
    }
}
