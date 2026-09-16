<?php

namespace Tests\Feature\Installments;

use App\Domain\Installments\DepositPlanService;
use App\Domain\Installments\Jobs\SendPlanActivationLinkJob;
use App\Domain\Installments\PlanActivation;
use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Filament\Pages\ProductDetail;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Mail\PlanActivationMail;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Services\Orders\PaidOrderPlanResolverFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A SUBSCRIPTION THE CUSTOMER STARTS.
 *
 * The product plan says `requires_activation`. The checkout takes the first cycle as it
 * always has — but the subscription waits at `awaiting_activation`, with no charge date,
 * until the customer confirms the link they are emailed. The next charge is then one
 * cycle from THAT day.
 *
 * What must never happen: a waiting subscription billed by anything, a mail scanner
 * starting somebody's subscription by opening the email, or a revoked link still working.
 */
final class PlanActivationTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CYCLE = 39.0;

    protected function tearDown(): void
    {
        PaidOrderPlanResolverFactory::clearFake();
        Carbon::setTestNow();
        Tenant::clear();
        parent::tearDown();
    }

    // === At checkout ===

    public function test_a_paid_checkout_waits_for_activation_with_no_charge_date_and_emails_the_link(): void
    {
        Bus::fake([SendPlanActivationLinkJob::class]);
        [$shop, $token] = $this->shopWithToken();
        $plan = $this->awaitingPaymentPlan($shop, requiresActivation: true);

        $this->postJson('/woocommerce/deposit/callback/'.$token, [
            'transaction' => ['more_info' => $plan->public_id, 'status_code' => '000', 'uid' => 'txn-a1'],
        ])->assertOk();

        Tenant::run($shop, function () use ($plan): void {
            $fresh = $plan->fresh();

            $this->assertSame(PlanStatus::AWAITING_ACTIVATION, $fresh->status);
            $this->assertNull($fresh->next_charge_at, 'nothing may be scheduled before the customer starts it');

            // Everything else about the checkout is exactly as it always was.
            $this->assertEqualsWithDelta(self::CYCLE, (float) $fresh->total_charged, 0.001);
            $this->assertSame(1, PaymentLedger::query()->where('plan_id', $fresh->id)->where('status', LedgerStatus::SUCCEEDED->value)->count());
            $this->assertSame(1, CustomerConsent::query()->where('consent_context', CustomerConsent::CONTEXT_RECURRING)->count());
        });

        Bus::assertDispatched(SendPlanActivationLinkJob::class, fn (SendPlanActivationLinkJob $job): bool => $job->shopId === (int) $shop->getKey() && $job->planId === (int) $plan->getKey());
    }

    public function test_a_plan_that_does_not_ask_for_activation_starts_at_checkout_as_before(): void
    {
        Bus::fake([SendPlanActivationLinkJob::class]);
        [$shop, $token] = $this->shopWithToken();
        $plan = $this->awaitingPaymentPlan($shop, requiresActivation: false);

        $this->postJson('/woocommerce/deposit/callback/'.$token, [
            'transaction' => ['more_info' => $plan->public_id, 'status_code' => '000', 'uid' => 'txn-a2'],
        ])->assertOk();

        $fresh = Tenant::run($shop, fn () => $plan->fresh());
        $this->assertSame(PlanStatus::ACTIVE, $fresh->status);
        $this->assertNotNull($fresh->next_charge_at);
        Bus::assertNotDispatched(SendPlanActivationLinkJob::class);
    }

    public function test_the_scheduler_never_bills_a_subscription_waiting_for_activation(): void
    {
        [$shop] = $this->shopWithToken();
        // Even with a charge date somehow in the past, the status alone keeps it off the list.
        $plan = $this->waitingPlan($shop, ['next_charge_at' => now()->subDay()]);

        Queue::fake();
        $this->artisan('payplus:dispatch-due')->assertExitCode(0);

        Queue::assertNotPushed(ChargeJob::class, fn (ChargeJob $job): bool => $job->planId === (int) $plan->getKey());
        $this->assertNotContains(PlanStatus::AWAITING_ACTIVATION->value, PlanStatus::chargeable());
    }

    // === The link ===

    public function test_opening_the_link_changes_nothing_and_confirming_starts_it_one_cycle_from_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 10:30:00'));
        [$shop] = $this->shopWithToken();
        $plan = $this->waitingPlan($shop);
        [$url, $activateUrl] = Tenant::run($shop, fn (): array => [app(PlanActivation::class)->url($plan), app(PlanActivation::class)->activateUrl($plan)]);

        // A mail scanner (or the customer) opens the email's link.
        $this->get($url)->assertOk()->assertSee(__('activation.page.button', [], 'he'));
        $this->assertSame(PlanStatus::AWAITING_ACTIVATION, Tenant::run($shop, fn () => $plan->fresh()->status));

        // The customer presses the button.
        $this->post($activateUrl)->assertOk()->assertSee('17/10/2026');

        Tenant::run($shop, function () use ($plan): void {
            $fresh = $plan->fresh();
            $this->assertSame(PlanStatus::ACTIVE, $fresh->status);
            $this->assertSame('2026-10-17 00:00:00', $fresh->next_charge_at->toDateTimeString());
            $this->assertSame(ActivityEvent::ACTOR_CUSTOMER, ActivityEvent::query()->where('plan_id', $fresh->id)->where('kind', PlanActivation::KIND_ACTIVATED)->value('actor'));
        });

        // A second press a day later changes nothing — and says it is active.
        Carbon::setTestNow(Carbon::parse('2026-09-18 09:00:00'));
        $this->post($activateUrl)->assertOk()->assertSee(__('activation.page.active_heading', [], 'he'));
        $this->assertSame('2026-10-17', Tenant::run($shop, fn () => $plan->fresh()->next_charge_at->toDateString()));
    }

    public function test_a_two_week_plan_is_counted_in_its_own_cycle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 10:30:00'));
        [$shop] = $this->shopWithToken();
        $plan = $this->waitingPlan($shop, ['billing_frequency' => BillingFrequency::WEEKLY->value, 'interval_count' => 2]);

        $active = Tenant::run($shop, fn () => app(PlanActivation::class)->activate($plan));

        $this->assertSame('2026-10-01', $active->next_charge_at->toDateString());
    }

    public function test_an_edited_link_is_refused_by_its_signature(): void
    {
        [$shop] = $this->shopWithToken();
        $plan = $this->waitingPlan($shop);
        $url = Tenant::run($shop, fn () => app(PlanActivation::class)->url($plan));

        $this->get($url.'x')->assertForbidden();
        $this->get(str_replace((string) $plan->public_id, 'PLN-SOMEONE-ELSE', $url))->assertForbidden();
    }

    public function test_a_revoked_link_stops_working_and_the_new_one_works(): void
    {
        [$shop] = $this->shopWithToken();
        $plan = $this->waitingPlan($shop);
        $old = Tenant::run($shop, fn () => app(PlanActivation::class)->activateUrl($plan));

        Tenant::run($shop, fn () => app(PlanActivation::class)->revoke($plan->fresh()));

        $this->post($old)->assertStatus(410);
        $this->assertSame(PlanStatus::AWAITING_ACTIVATION, Tenant::run($shop, fn () => $plan->fresh()->status));

        $new = Tenant::run($shop, fn () => app(PlanActivation::class)->activateUrl($plan->fresh()));
        $this->post($new)->assertOk();
        $this->assertSame(PlanStatus::ACTIVE, Tenant::run($shop, fn () => $plan->fresh()->status));
    }

    public function test_a_cancelled_subscription_cannot_be_started_from_its_link(): void
    {
        [$shop] = $this->shopWithToken();
        $plan = $this->waitingPlan($shop);
        $url = Tenant::run($shop, fn () => app(PlanActivation::class)->activateUrl($plan));

        Tenant::run($shop, fn () => app(SubscriptionLifecycleService::class)->cancel($plan->fresh(), notify: false));

        $this->post($url)->assertStatus(410);
        $this->assertSame(PlanStatus::CANCELLED, Tenant::run($shop, fn () => $plan->fresh()->status));
    }

    public function test_the_email_carries_the_link_to_the_customer(): void
    {
        Mail::fake();
        [$shop] = $this->shopWithToken();
        $plan = $this->waitingPlan($shop);

        (new SendPlanActivationLinkJob((int) $shop->getKey(), (int) $plan->getKey()))->handle(app(PlanActivation::class));

        $url = Tenant::run($shop, fn () => app(PlanActivation::class)->url($plan->fresh()));
        Mail::assertSent(PlanActivationMail::class, fn (PlanActivationMail $mail): bool => $mail->activationUrl === $url
            && $mail->hasTo('subscriber@example.com'));
    }

    // === Admin ===

    public function test_the_merchant_can_start_it_for_the_customer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 10:30:00'));
        [$shop] = $this->shopWithToken();
        $plan = $this->waitingPlan($shop);
        $this->signIn($shop);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionVisible('activateNow')
            ->assertActionVisible('activationLink')
            ->assertActionHidden('chargeNow')
            ->assertActionHidden('editNextCharge')
            ->callAction('activateNow');

        $fresh = $plan->fresh();
        $this->assertSame(PlanStatus::ACTIVE, $fresh->status);
        $this->assertSame('2026-10-17', $fresh->next_charge_at->toDateString());
    }

    public function test_the_product_plan_drawer_turns_activation_on_but_not_on_the_shopify_payments_rail(): void
    {
        [$shop] = $this->shopWithToken();
        $this->signIn($shop);
        $template = $this->template($shop, requiresActivation: false);

        Livewire::test(ProductDetail::class, ['product' => $template->product_id])
            ->call('openPlanConfig', $template->id)
            ->set('requiresActivation', true)
            ->call('savePlanConfig');

        $this->assertTrue((bool) $template->fresh()->requires_activation);

        Livewire::test(ProductDetail::class, ['product' => $template->product_id])
            ->call('openPlanConfig', $template->id)
            ->set('billingRail', ProductSubscriptionPlan::RAIL_SHOPIFY_PAYMENTS)
            ->set('requiresActivation', true)
            ->call('savePlanConfig');

        $this->assertFalse((bool) $template->fresh()->requires_activation);
    }

    // === Fixtures ===

    /** @return array{0: Shop, 1: string} */
    private function shopWithToken(): array
    {
        $token = (string) Str::ulid();
        $domain = 'activation-'.Str::lower(Str::random(6)).'.example.com';
        $shop = Shop::create([
            'woocommerce_domain' => $domain,
            'name' => 'Activation Books',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $shop->wc_shop_token = $token;
        $shop->woocommerce_credentials = ['base_url' => 'https://'.$domain];
        $shop->save();

        return [$shop->fresh(), $token];
    }

    private function signIn(Shop $shop): void
    {
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());
    }

    private function template(Shop $shop, bool $requiresActivation): ProductSubscriptionPlan
    {
        return Tenant::run($shop, function () use ($shop, $requiresActivation): ProductSubscriptionPlan {
            $product = new Product;
            $product->forceFill([
                'shop_id' => $shop->id,
                'source' => Product::SOURCE_WOOCOMMERCE,
                'external_id' => (string) random_int(1000, 99999),
                'title' => 'Monthly box',
                'status' => Product::STATUS_ACTIVE,
                'online_store_status' => Product::ONLINE_PUBLISHED,
            ])->save();

            $template = new ProductSubscriptionPlan;
            $template->forceFill([
                'shop_id' => $shop->id,
                'product_id' => $product->id,
                'plan_type' => ProductSubscriptionPlan::TYPE_SUBSCRIPTION,
                'plan_kind' => 'recurring',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
                'discount_value' => 0,
                'channels' => [ProductSubscriptionPlan::CHANNEL_STOREFRONT_WIDGET],
                'status' => PlanTemplateStatus::ACTIVE->value,
                'position' => 0,
                'requires_activation' => $requiresActivation,
            ])->save();

            return $template;
        });
    }

    /** A recurring plan at checkout, before the PayPlus callback says it was paid. */
    private function awaitingPaymentPlan(Shop $shop, bool $requiresActivation): InstallmentPlan
    {
        $template = $this->template($shop, $requiresActivation);

        return Tenant::run($shop, function () use ($shop, $template): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->fill($this->planAttributes() + [
                'product_subscription_plan_id' => $template->id,
                'meta' => [DepositPlanService::META_DEPOSIT_AMOUNT => self::CYCLE],
            ]);
            $plan->forceFill(['shop_id' => (int) $shop->getKey(), 'status' => PlanStatus::AWAITING_FIRST_PAYMENT->value])->save();

            return $plan->fresh();
        });
    }

    /**
     * A paid plan already waiting for its customer, with a card on file.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function waitingPlan(Shop $shop, array $overrides = []): InstallmentPlan
    {
        $template = $this->template($shop, requiresActivation: true);

        return Tenant::run($shop, function () use ($shop, $template, $overrides): InstallmentPlan {
            $method = InstallmentPaymentMethod::create([
                'shopify_customer_id' => 'cust-act',
                'payplus_card_token_uid' => 'tok-'.Str::random(8),
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            $plan = new InstallmentPlan;
            $plan->fill(array_merge($this->planAttributes(), [
                'product_subscription_plan_id' => $template->id,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => 'cust-act',
                'total_charged' => self::CYCLE,
            ], $overrides));
            $plan->forceFill(['shop_id' => (int) $shop->getKey(), 'status' => PlanStatus::AWAITING_ACTIVATION->value])->save();

            return $plan->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function planAttributes(): array
    {
        return [
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'total_amount' => self::CYCLE,
            'total_charged' => 0,
            'installment_amount' => self::CYCLE,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'next_charge_at' => null,
            'public_id' => 'PLN-'.Str::upper(Str::random(12)),
            'customer_name' => 'Dana Reader',
            'customer_email' => 'subscriber@example.com',
        ];
    }
}
