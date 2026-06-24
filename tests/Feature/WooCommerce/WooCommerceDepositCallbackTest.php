<?php

namespace Tests\Feature\WooCommerce;

use App\Domain\Installments\DepositPlanService;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Orders\PaidOrderPlanResolverFactory;
use App\Services\WooCommerce\Orders\WooCommercePaidOrderPlanResolver;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W11 P2 — the PayPlus → SaaS deposit callback (the WooCommerce analogue of orders/paid).
 * PayPlus POSTs to /woocommerce/deposit/callback/{wc_shop_token} on payment completion,
 * echoing more_info (= plan public_id). The controller resolves the shop from the token
 * BEFORE trusting the body, finds the plan via WooCommercePaidOrderPlanResolver, and runs
 * PlanActivationService — recording the paid deposit + flipping the plan active. It is
 * replay-safe (a twice-delivered callback activates exactly once) and money-gated (the
 * deposit amount comes from the plan's STORED quote, not the callback body).
 */
final class WooCommerceDepositCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PaidOrderPlanResolverFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_resolver_factory_routes_woocommerce_to_the_wc_resolver(): void
    {
        $this->assertInstanceOf(
            WooCommercePaidOrderPlanResolver::class,
            PaidOrderPlanResolverFactory::for(new Shop(['platform' => Shop::PLATFORM_WOOCOMMERCE])),
        );
    }

    public function test_a_success_callback_activates_the_plan_once_and_is_replay_safe(): void
    {
        [$shop, $token] = $this->shopWithToken('cb.example.com');
        $plan = $this->awaitingPlan($shop, 'PUB-CB-1', deposit: 100.0);

        $path = '/woocommerce/deposit/callback/'.$token;
        $body = ['transaction' => ['more_info' => 'PUB-CB-1', 'status_code' => '000', 'uid' => 'txn-1']];

        $first = $this->postJson($path, $body);
        $first->assertOk()->assertJsonPath('activated', true)->assertJsonPath('plan_public_id', 'PUB-CB-1');

        // Replay: same callback again must NOT double-activate / double-record the deposit.
        $this->postJson($path, $body)->assertOk();

        $plan = Tenant::run($shop, fn (): ?InstallmentPlan => InstallmentPlan::query()->where('public_id', 'PUB-CB-1')->first());
        $this->assertSame(PlanStatus::ACTIVE, $plan->status);
        $this->assertSame(100.0, round((float) $plan->total_charged, 2));

        // Exactly one SUCCEEDED deposit ledger row (idempotent on the deposit key).
        $deposits = Tenant::run($shop, fn (): int => PaymentLedger::query()
            ->where('plan_id', $plan->getKey())
            ->where('charge_context', 'deposit')
            ->count());
        $this->assertSame(1, $deposits);
    }

    public function test_a_failure_callback_does_not_activate(): void
    {
        [$shop, $token] = $this->shopWithToken('cb-fail.example.com');
        $this->awaitingPlan($shop, 'PUB-CB-2', deposit: 50.0);

        $this->postJson('/woocommerce/deposit/callback/'.$token, [
            'transaction' => ['more_info' => 'PUB-CB-2', 'status_code' => '999'],
        ])->assertOk()->assertJsonPath('activated', false);

        $plan = Tenant::run($shop, fn (): ?InstallmentPlan => InstallmentPlan::query()->where('public_id', 'PUB-CB-2')->first());
        $this->assertSame(PlanStatus::AWAITING_FIRST_PAYMENT, $plan->status);
    }

    public function test_an_unknown_shop_token_is_404(): void
    {
        $this->postJson('/woocommerce/deposit/callback/not-a-real-token', [
            'transaction' => ['more_info' => 'X', 'status_code' => '000'],
        ])->assertStatus(404);
    }

    public function test_a_callback_can_never_activate_another_shops_plan(): void
    {
        [$shopA] = $this->shopWithToken('cb-iso-a.example.com');
        $this->awaitingPlan($shopA, 'PUB-A-ISO', deposit: 100.0);

        [, $tokenB] = $this->shopWithToken('cb-iso-b.example.com');

        // A success callback on shop B's token, naming shop A's plan → no activation
        // (the plan is tenant-scoped to A; B's resolver never sees it).
        $this->postJson('/woocommerce/deposit/callback/'.$tokenB, [
            'transaction' => ['more_info' => 'PUB-A-ISO', 'status_code' => '000'],
        ])->assertOk()->assertJsonPath('activated', false);

        $plan = Tenant::run($shopA, fn (): ?InstallmentPlan => InstallmentPlan::query()->where('public_id', 'PUB-A-ISO')->first());
        $this->assertSame(PlanStatus::AWAITING_FIRST_PAYMENT, $plan->status);
    }

    // === Helpers ===

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

    /** An awaiting_first_payment plan with a stored quote/deposit (what activation reads). */
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
}
