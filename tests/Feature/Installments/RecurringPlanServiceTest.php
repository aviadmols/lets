<?php

namespace Tests\Feature\Installments;

use App\Domain\Installments\DepositPlanService;
use App\Domain\Installments\RecurringPlanService;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The recurring plan ROW, and the promise that adding a third caller changed
 * nothing for the two that already existed.
 *
 * createForCustomer() widened buildPlanRow() with identity, a saved card, a first
 * charge date and extra meta. Every one of those is optional, so the checkout
 * paths must still write byte-identical rows — a silent change there would land
 * on every new subscription in every shop, which is why it is asserted
 * field-by-field rather than assumed.
 */
final class RecurringPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    /** Columns the checkout path owns. A change to any of them is a regression. */
    private const CHECKOUT_COLUMNS = [
        'plan_kind', 'charge_context', 'status', 'customer_id', 'shopify_customer_id',
        'external_customer_id', 'payment_method_id', 'external_product_id', 'external_variant_id',
        'total_amount', 'total_charged', 'installment_amount', 'regular_amount', 'currency',
        'billing_frequency', 'interval_count', 'next_charge_at', 'requires_manual_payment',
        'customer_email', 'customer_name', 'customer_phone', 'product_subscription_plan_id',
    ];

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_checkout_path_still_writes_the_row_it_always_did(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $plan = app(RecurringPlanService::class)->createAwaitingExternalPayment($shop, $this->checkoutContext());

            $row = $plan->fresh()->only(self::CHECKOUT_COLUMNS);

            $this->assertSame([
                'plan_kind' => PlanKind::RECURRING,
                'charge_context' => 'recurring',
                'status' => PlanStatus::AWAITING_FIRST_PAYMENT,
                // The checkout knows only an external reference, and no card yet.
                'customer_id' => null,
                'shopify_customer_id' => 'wc-88',
                'external_customer_id' => 'wc-88',
                'payment_method_id' => null,
                'external_product_id' => '2675',
                'external_variant_id' => '2675',
                'total_amount' => '89.00',
                'total_charged' => '0.00',
                'installment_amount' => '89.00',
                'regular_amount' => null,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY,
                'interval_count' => 1,
                // NULL until the first payment activates the plan.
                'next_charge_at' => null,
                'requires_manual_payment' => false,
                'customer_email' => 'buyer@example.com',
                'customer_name' => 'Buyer',
                'customer_phone' => null,
                'product_subscription_plan_id' => null,
            ], $row);

            // Keys AND values, but the amount loosely: a whole float survives the
            // JSON round trip as an int, which is a storage detail and not a change.
            $meta = $plan->fresh()->meta;
            $this->assertSame(
                [DepositPlanService::META_DEPOSIT_AMOUNT, InstallmentPlan::META_ITEM_TITLE],
                array_keys($meta),
            );
            $this->assertEqualsWithDelta(89.0, (float) $meta[DepositPlanService::META_DEPOSIT_AMOUNT], 0.001);
            $this->assertSame('Monthly box', $meta[InstallmentPlan::META_ITEM_TITLE]);
        });
    }

    public function test_create_for_customer_copies_identity_card_date_and_meta(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $method = InstallmentPaymentMethod::create([
                'shopify_customer_id' => 'legacy-uuid',
                'payplus_card_token_uid' => 'tok-1',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            $firstCharge = now()->addDays(12)->startOfDay();

            $plan = app(RecurringPlanService::class)->createForCustomer($shop, $this->checkoutContext([
                // The imported shape: a UUID reference and NO internal id. A plan
                // built from anything else could never satisfy the consent gate.
                'customer_id' => null,
                'shopify_customer_id' => 'legacy-uuid',
                'external_customer_id' => 'legacy-uuid',
                'payment_method_id' => $method->getKey(),
                'first_charge_at' => $firstCharge,
                'meta' => [InstallmentPlan::META_ACCOUNT_OFFER => ['offer_id' => '7', 'one_shot' => true]],
            ]));

            $fresh = $plan->fresh();

            $this->assertSame(PlanStatus::AWAITING_FIRST_PAYMENT, $fresh->status);
            $this->assertSame('legacy-uuid', $fresh->shopify_customer_id);
            $this->assertNull($fresh->customer_id);
            $this->assertSame($method->getKey(), $fresh->payment_method_id);
            $this->assertSame($firstCharge->toDateString(), $fresh->next_charge_at->toDateString());
            $this->assertFalse((bool) $fresh->requires_manual_payment);

            // The caller's meta is MERGED, never a replacement: the first-payment
            // amount and the item title still have to be there.
            $this->assertEqualsWithDelta(89.0, (float) $fresh->meta[DepositPlanService::META_DEPOSIT_AMOUNT], 0.001);
            $this->assertSame('Monthly box', $fresh->meta[InstallmentPlan::META_ITEM_TITLE]);
            $this->assertTrue($fresh->isOneShotOffer());
            $this->assertSame('7', $fresh->accountOfferMeta()['offer_id']);

            $this->assertDatabaseHas('activity_events', [
                'shop_id' => $shop->getKey(),
                'plan_id' => $plan->getKey(),
                'kind' => 'recurring_plan_created',
            ]);
        });
    }

    public function test_a_zero_or_negative_amount_is_refused(): void
    {
        $shop = $this->makeShop();

        Tenant::run($shop, function () use ($shop): void {
            $this->expectException(\RuntimeException::class);

            app(RecurringPlanService::class)->createForCustomer($shop, $this->checkoutContext(['amount' => 0]));
        });
    }

    // === Fixtures ===

    private function makeShop(): Shop
    {
        return Shop::create([
            'shopify_domain' => 'recurring.example.com',
            'name' => 'Recurring Co',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function checkoutContext(array $overrides = []): array
    {
        return array_merge([
            'product_gid' => '2675',
            'variant_gid' => '2675',
            'item_title' => 'Monthly box',
            'amount' => 89.0,
            'frequency' => BillingFrequency::MONTHLY,
            'interval_count' => 1,
            'currency' => 'ILS',
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Buyer',
            'external_customer_id' => 'wc-88',
        ], $overrides);
    }
}
