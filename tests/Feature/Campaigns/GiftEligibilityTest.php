<?php

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\GiftEligibility;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who has earned a gift.
 *
 * The load-bearing choice: a cycle is a charge that SUCCEEDED. Counting attempts
 * would reward a customer whose card kept declining, and counting the plan's age
 * would reward one who never paid at all.
 */
final class GiftEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_threshold_is_inclusive(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $this->plan($shop, 'Two', succeeded: 2);
        $this->plan($shop, 'Three', succeeded: 3);

        $rows = app(GiftEligibility::class)->qualifying(3);

        // "At least 3" means 3 qualifies. An exclusive reading would quietly deny
        // the gift to everyone who just reached the milestone.
        $this->assertSame(['Three'], $rows->pluck('label')->all());
    }

    public function test_failed_attempts_are_not_cycles(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        // Three attempts, one paid. A customer whose card kept declining has not
        // been a loyal subscriber for three cycles.
        $this->plan($shop, 'Declined', succeeded: 1, failed: 2);

        $this->assertCount(0, app(GiftEligibility::class)->qualifying(3));
        $this->assertCount(1, app(GiftEligibility::class)->qualifying(1));
    }

    public function test_a_cancelled_subscriber_is_not_thanked(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $plan = $this->plan($shop, 'Gone', succeeded: 5);
        $plan->forceFill(['status' => PlanStatus::CANCELLED->value])->save();

        // They met the bar once, but the campaign thanks CURRENT subscribers.
        $this->assertCount(0, app(GiftEligibility::class)->qualifying(1));
    }

    public function test_a_one_time_installment_plan_is_not_a_subscription(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $plan = $this->plan($shop, 'Instalments', succeeded: 4);
        $plan->forceFill(['plan_kind' => PlanKind::INSTALLMENTS->value])->save();

        $this->assertCount(0, app(GiftEligibility::class)->qualifying(1));
    }

    public function test_shopify_contracts_qualify_on_succeeded_attempts(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $this->contract($shop, 'Contract Buyer', 'c@example.com', succeeded: 4, failed: 1);

        $rows = app(GiftEligibility::class)->qualifying(4);

        $this->assertSame(['Contract Buyer'], $rows->pluck('label')->all());
        $this->assertSame('shopify', $rows->first()['rail']);
        $this->assertSame(4, $rows->first()['cycles']);
    }

    public function test_one_person_with_two_subscriptions_gets_one_gift(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $this->plan($shop, 'Dana', succeeded: 3, email: 'dana@example.com');
        $this->plan($shop, 'Dana', succeeded: 5, email: 'DANA@example.com');

        // One human, one package — matched case-insensitively, because a store
        // that stored the address in caps did not create a second customer.
        $this->assertCount(1, app(GiftEligibility::class)->qualifying(1));
    }

    public function test_subscribers_without_an_email_are_never_merged(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $this->plan($shop, 'Guest A', succeeded: 3, email: null);
        $this->plan($shop, 'Guest B', succeeded: 3, email: null);

        // Two anonymous subscriptions might be one person or two. Guessing they
        // are one would silently deny someone their gift.
        $this->assertCount(2, app(GiftEligibility::class)->qualifying(1));
    }

    public function test_another_shops_subscribers_are_invisible(): void
    {
        $mine = $this->shop();
        $theirs = $this->shop('other-gift.example.com');

        Tenant::run($theirs, fn () => $this->plan($theirs, 'Theirs', succeeded: 9));

        Tenant::set($mine);

        $this->assertCount(0, app(GiftEligibility::class)->qualifying(1));
    }

    // === Fixtures ===

    private function shop(string $domain = 'gift-elig.example.com'): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    private function plan(Shop $shop, string $name, int $succeeded, int $failed = 0, ?string $email = 'buyer@example.com'): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $name, $succeeded, $failed, $email): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'total_amount' => 100,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'public_id' => (string) Str::ulid(),
                'customer_name' => $name,
                'customer_email' => $email,
            ]);
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => PlanStatus::ACTIVE->value,
            ])->save();

            $sequence = 0;
            foreach ([[$succeeded, PaymentStatus::SUCCEEDED], [$failed, PaymentStatus::FAILED]] as [$count, $status]) {
                for ($i = 0; $i < $count; $i++) {
                    $payment = new InstallmentPayment;
                    $payment->forceFill([
                        'shop_id' => (int) $shop->getKey(),
                        'plan_id' => $plan->getKey(),
                        'payment_type' => PaymentType::RECURRING->value,
                        'sequence' => ++$sequence,
                        'amount' => 100,
                        'currency' => 'ILS',
                        'status' => $status->value,
                    ])->save();
                }
            }

            return $plan;
        });
    }

    private function contract(Shop $shop, string $name, string $email, int $succeeded, int $failed = 0): SubscriptionContract
    {
        return Tenant::run($shop, function () use ($shop, $name, $email, $succeeded, $failed): SubscriptionContract {
            $contract = new SubscriptionContract;
            $contract->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'shopify_gid' => 'gid://shopify/SubscriptionContract/'.Str::random(6),
                'status' => SubscriptionContract::STATUS_ACTIVE,
                'currency' => 'ILS',
                'customer_name' => $name,
                'customer_email' => $email,
            ])->save();

            $cycle = 0;
            foreach ([[$succeeded, SubscriptionBillingAttempt::STATUS_SUCCEEDED], [$failed, SubscriptionBillingAttempt::STATUS_FAILED]] as [$count, $status]) {
                for ($i = 0; $i < $count; $i++) {
                    $attempt = new SubscriptionBillingAttempt;
                    $attempt->forceFill([
                        'shop_id' => (int) $shop->getKey(),
                        'subscription_contract_id' => $contract->getKey(),
                        'billing_cycle_key' => '2026-0'.(++$cycle),
                        'idempotency_key' => (string) Str::ulid(),
                        'status' => $status,
                    ])->save();
                }
            }

            return $contract;
        });
    }
}
