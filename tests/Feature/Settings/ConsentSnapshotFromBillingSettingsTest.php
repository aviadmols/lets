<?php

namespace Tests\Feature\Settings;

use App\Domain\Installments\DepositPlanService;
use App\Models\CustomerConsent;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Orders\PaidOrderPlanResolverFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * When a deposit is paid and the plan activates (PlanActivationService::recordConsent),
 * the CustomerConsent row must snapshot the SHOP'S terms version + cancellation policy
 * from its MerchantBillingSettings — so a future dispute is answerable against exactly
 * the policy in force at acceptance. Driven through the real WooCommerce deposit
 * callback (the WC analogue of orders/paid), which runs the same activation path.
 */
final class ConsentSnapshotFromBillingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PaidOrderPlanResolverFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_consent_snapshots_the_shops_terms_version_and_cancellation_policy(): void
    {
        [$shop, $token] = $this->shopWithToken('consent.example.com');

        // The merchant sets their policy BEFORE the deposit is paid.
        Tenant::run($shop, function (): void {
            $s = MerchantBillingSettings::current();
            $s->terms_version = 'v7';
            $s->cancellation_policy_text = 'Cancel any time before the next charge.';
            $s->save();
        });

        $this->awaitingPlan($shop, 'PUB-CONSENT-1', deposit: 100.0);

        $this->postJson('/woocommerce/deposit/callback/'.$token, [
            'transaction' => ['more_info' => 'PUB-CONSENT-1', 'status_code' => '000', 'uid' => 'txn-c1'],
        ])->assertOk()->assertJsonPath('activated', true);

        $consent = Tenant::run($shop, fn (): ?CustomerConsent => CustomerConsent::query()
            ->where('consent_context', CustomerConsent::CONTEXT_INSTALLMENTS)
            ->first());

        $this->assertNotNull($consent);
        $this->assertSame('v7', $consent->accepted_terms_version);
        $this->assertSame('Cancel any time before the next charge.', $consent->cancellation_policy_snapshot);
    }

    public function test_consent_uses_the_default_terms_version_when_the_merchant_set_none(): void
    {
        [$shop, $token] = $this->shopWithToken('consent-default.example.com');
        $this->awaitingPlan($shop, 'PUB-CONSENT-2', deposit: 100.0);

        $this->postJson('/woocommerce/deposit/callback/'.$token, [
            'transaction' => ['more_info' => 'PUB-CONSENT-2', 'status_code' => '000', 'uid' => 'txn-c2'],
        ])->assertOk()->assertJsonPath('activated', true);

        $consent = Tenant::run($shop, fn (): ?CustomerConsent => CustomerConsent::query()
            ->where('consent_context', CustomerConsent::CONTEXT_INSTALLMENTS)
            ->first());

        $this->assertNotNull($consent);
        $this->assertSame(MerchantBillingSettings::DEFAULT_TERMS_VERSION, $consent->accepted_terms_version);
        $this->assertNull($consent->cancellation_policy_snapshot);
    }

    public function test_recurring_activation_records_a_recurring_consent_so_the_engine_can_charge_cycles(): void
    {
        // Regression: recordConsent once hardcoded CONTEXT_INSTALLMENTS, but the charge
        // engine's gate (ChargeOrchestrator::consentContextFor) looks up CONTEXT_RECURRING
        // for a recurring plan — so a subscription would carry a consent the gate never
        // finds and would fail closed (no_consent), never charging a cycle. The recorded
        // context must follow plan_kind.
        [$shop, $token] = $this->shopWithToken('recurring-consent.example.com');
        $this->awaitingRecurringPlan($shop, 'PUB-REC-1', cycle: 60.0);

        $this->postJson('/woocommerce/deposit/callback/'.$token, [
            'transaction' => ['more_info' => 'PUB-REC-1', 'status_code' => '000', 'uid' => 'txn-r1'],
        ])->assertOk()->assertJsonPath('activated', true);

        Tenant::run($shop, function (): void {
            // The recurring consent the engine's gate requires exists…
            $this->assertSame(1, CustomerConsent::query()
                ->where('consent_context', CustomerConsent::CONTEXT_RECURRING)->count());
            // …and NOT an installments one the gate would never look up for this plan.
            $this->assertSame(0, CustomerConsent::query()
                ->where('consent_context', CustomerConsent::CONTEXT_INSTALLMENTS)->count());
        });
    }

    // === Helpers (mirror WooCommerceDepositCallbackTest) ===

    /** @return array{0:Shop,1:string} [shop, wc_shop_token] */
    private function shopWithToken(string $domain): array
    {
        $token = (string) Str::ulid();
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->wc_shop_token = $token;
        $shop->woocommerce_credentials = ['base_url' => 'https://'.$domain];
        $shop->save();

        return [$shop->fresh(), $token];
    }

    private function awaitingPlan(Shop $shop, string $publicId, float $deposit): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $publicId, $deposit): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill([
                'plan_kind' => PlanKind::INSTALLMENTS->value,
                'charge_context' => 'deposit',
                'total_amount' => 400,
                'total_charged' => 0,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'public_id' => $publicId,
                'customer_email' => 'buyer@example.com',
                'meta' => [
                    DepositPlanService::META_DEPOSIT_AMOUNT => $deposit,
                    DepositPlanService::META_QUOTE => [
                        'schedule' => [['sequence' => 1, 'amount' => 100, 'due_at' => now()->addMonth()->toDateString()]],
                    ],
                ],
            ]);
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
            ])->save();

            return $plan->fresh();
        });
    }

    private function awaitingRecurringPlan(Shop $shop, string $publicId, float $cycle): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $publicId, $cycle): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'total_amount' => $cycle,
                'total_charged' => 0,
                'installment_amount' => $cycle,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'next_charge_at' => null,
                'public_id' => $publicId,
                'customer_email' => 'subscriber@example.com',
                'meta' => [
                    // The first-cycle amount the activation callback records as paid.
                    DepositPlanService::META_DEPOSIT_AMOUNT => $cycle,
                ],
            ]);
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
            ])->save();

            return $plan->fresh();
        });
    }
}
