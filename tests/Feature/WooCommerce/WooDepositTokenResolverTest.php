<?php

namespace Tests\Feature\WooCommerce;

use App\Domain\Installments\Contracts\DepositTokenResolver;
use App\Domain\Installments\DepositPlanService;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Orders\PlatformDepositTokenResolver;
use App\Services\WooCommerce\Orders\WooDepositTokenResolver;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FOLLOW-UP 3 — the WooCommerce DepositTokenResolver.
 *
 * The WC deposit is paid on the PayPlus hosted page, so PayPlus POSTs the reusable card
 * token + customer reference straight to our deposit callback. This resolver extracts
 * them; PlanActivationService vaults them as the plan's InstallmentPaymentMethod so the
 * engine can charge later cycles one-click. When the callback carries NO token, the
 * resolver no-ops cleanly (the plan still activates; only auto-charging needs the token).
 *
 * ASSUMED reusable-token field: `token_uid` (the PayPlus API field, per the reference
 * PayPlusCustomerTokenResolver) → vaulted as payplus_card_token_uid. customer_uid →
 * payplus_customer_uid. Owner to confirm against a real PayPlus WC callback.
 */
final class WooDepositTokenResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Resolver unit behaviour
    // ─────────────────────────────────────────────────────────────────────────

    public function test_it_extracts_the_token_from_the_wrapped_callback_body(): void
    {
        $shop = $this->makeShop('tok-extract.example.com');

        $resolved = (new WooDepositTokenResolver)->resolveFromOrder($shop, [
            'plan_public_id' => 'PUB-X',
            'id' => 'txn-1',
            'payplus' => [
                'transaction' => [
                    'more_info' => 'PUB-X',
                    'status_code' => '000',
                    'token_uid' => 'TOKEN-ABC',
                    'customer_uid' => 'CUST-99',
                    'four_digits' => '4242',
                    'brand_name' => 'visa',
                    'expiry_month' => 11,
                    'expiry_year' => 2031,
                ],
            ],
        ]);

        $this->assertSame('TOKEN-ABC', $resolved['payplus_card_token_uid']);
        $this->assertSame('CUST-99', $resolved['payplus_customer_uid']);
        $this->assertSame('4242', $resolved['card_last_four']);
        $this->assertSame('visa', $resolved['card_brand']);
        $this->assertSame(11, $resolved['exp_month']);
        $this->assertSame(2031, $resolved['exp_year']);
    }

    public function test_it_no_ops_when_the_callback_carries_no_token(): void
    {
        $shop = $this->makeShop('tok-none.example.com');

        $resolved = (new WooDepositTokenResolver)->resolveFromOrder($shop, [
            'plan_public_id' => 'PUB-Y',
            'payplus' => ['transaction' => ['more_info' => 'PUB-Y', 'status_code' => '000', 'uid' => 'txn-2']],
        ]);

        $this->assertNull($resolved);
    }

    public function test_it_resolves_a_customer_reference_even_without_a_token_uid(): void
    {
        $shop = $this->makeShop('tok-cust.example.com');

        $resolved = (new WooDepositTokenResolver)->resolveFromOrder($shop, [
            'payplus' => ['transaction' => ['customer_uid' => 'CUST-ONLY']],
        ]);

        $this->assertNull($resolved['payplus_card_token_uid']);
        $this->assertSame('CUST-ONLY', $resolved['payplus_customer_uid']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Platform router — Shopify stays unchanged (null), WooCommerce delegates
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_platform_router_delegates_woocommerce_and_nulls_shopify(): void
    {
        $router = app(PlatformDepositTokenResolver::class);

        $wooShop = new Shop(['platform' => Shop::PLATFORM_WOOCOMMERCE]);
        $shopifyShop = new Shop(['platform' => Shop::PLATFORM_SHOPIFY]);

        $payload = ['payplus' => ['transaction' => ['token_uid' => 'T-1', 'customer_uid' => 'C-1']]];

        $this->assertSame('T-1', $router->resolveFromOrder($wooShop, $payload)['payplus_card_token_uid']);
        // Shopify path is unchanged: no token captured via this seam.
        $this->assertNull($router->resolveFromOrder($shopifyShop, $payload));
    }

    public function test_the_container_binds_the_platform_router_as_the_resolver(): void
    {
        $this->assertInstanceOf(PlatformDepositTokenResolver::class, app(DepositTokenResolver::class));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Through the full deposit-callback activation path
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_token_carrying_deposit_callback_vaults_a_payment_method(): void
    {
        $shop = $this->makeShop('tok-flow.example.com');
        $token = (string) $shop->wc_shop_token;
        $this->awaitingPlan($shop, 'PUB-TOK-FLOW');

        $this->postJson('/woocommerce/deposit/callback/'.$token, [
            'transaction' => [
                'more_info' => 'PUB-TOK-FLOW',
                'status_code' => '000',
                'token_uid' => 'TOKEN-FLOW',
                'customer_uid' => 'CUST-FLOW',
                'four_digits' => '1234',
                'brand_name' => 'mastercard',
            ],
        ])->assertOk()->assertJsonPath('activated', true);

        [$plan, $method] = Tenant::run($shop, function (): array {
            $plan = InstallmentPlan::query()->where('public_id', 'PUB-TOK-FLOW')->first();

            return [$plan, InstallmentPaymentMethod::query()->first()];
        });

        $this->assertSame(PlanStatus::ACTIVE, $plan->status);
        $this->assertNotNull($method, 'a payment method should have been vaulted from the token');
        $this->assertSame('TOKEN-FLOW', $method->payplus_card_token_uid);
        $this->assertSame('CUST-FLOW', $method->payplus_customer_uid);
        $this->assertSame('1234', $method->card_last_four);
        $this->assertSame((int) $method->getKey(), (int) $plan->payment_method_id);
    }

    public function test_a_tokenless_deposit_callback_still_activates_without_a_method(): void
    {
        $shop = $this->makeShop('tok-flow-none.example.com');
        $token = (string) $shop->wc_shop_token;
        $this->awaitingPlan($shop, 'PUB-TOK-NONE');

        $this->postJson('/woocommerce/deposit/callback/'.$token, [
            'transaction' => ['more_info' => 'PUB-TOK-NONE', 'status_code' => '000', 'uid' => 'txn-none'],
        ])->assertOk()->assertJsonPath('activated', true);

        [$plan, $methodCount] = Tenant::run($shop, function (): array {
            $plan = InstallmentPlan::query()->where('public_id', 'PUB-TOK-NONE')->first();

            return [$plan, InstallmentPaymentMethod::query()->count()];
        });

        $this->assertSame(PlanStatus::ACTIVE, $plan->status);
        $this->assertNull($plan->payment_method_id);
        $this->assertSame(0, $methodCount);
    }

    public function test_a_vaulted_method_is_tenant_isolated(): void
    {
        $shopA = $this->makeShop('tok-iso-a.example.com');
        $this->awaitingPlan($shopA, 'PUB-ISO-A');
        $this->postJson('/woocommerce/deposit/callback/'.$shopA->wc_shop_token, [
            'transaction' => ['more_info' => 'PUB-ISO-A', 'status_code' => '000', 'token_uid' => 'TOK-A', 'customer_uid' => 'C-A'],
        ])->assertOk();

        $shopB = $this->makeShop('tok-iso-b.example.com');

        // Shop B sees ZERO payment methods — shop A's vaulted token is tenant-scoped.
        $countForB = Tenant::run($shopB, fn (): int => InstallmentPaymentMethod::query()->count());
        $this->assertSame(0, $countForB);

        $countForA = Tenant::run($shopA, fn (): int => InstallmentPaymentMethod::query()->count());
        $this->assertSame(1, $countForA);
    }

    // === Helpers ===

    private function makeShop(string $domain): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->woocommerce_credentials = ['base_url' => 'https://'.$domain];
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp'];
        $shop->save();

        return $shop->fresh();
    }

    private function awaitingPlan(Shop $shop, string $publicId): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $publicId): InstallmentPlan {
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
                'customer_email' => 'shopper@example.com',
                'meta' => [
                    DepositPlanService::META_DEPOSIT_AMOUNT => 100.0,
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
