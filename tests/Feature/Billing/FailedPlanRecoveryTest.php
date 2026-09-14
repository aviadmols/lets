<?php

namespace Tests\Feature\Billing;

use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A SUBSCRIPTION AT `failed` IS NOT A DEAD END.
 *
 * It was one. `failed` sits outside PlanStatus::chargeable(), which is right for
 * the SCHEDULER — we stopped asking on our own — and the detail page had turned
 * that into a rule about the MERCHANT too: no "Charge now", no "Resume", nothing
 * but "Cancel". Meanwhile the state machine allowed failed → active, the
 * orchestrator has no status gate at all, and both its success and failure paths
 * already walk a failed plan back up. The engine was ready; the screen offered no
 * way in.
 *
 * Found on the pilot store, on a real subscriber: seventeen migrated members the
 * CSV importer filed as `failed` because their source file said past_due — each
 * with a live vaulted card and a stored consent, and no button anywhere to take
 * their money.
 *
 * The third test is the one that would otherwise have shipped a lie: resuming a
 * plan with NO charge date produced an ACTIVE subscription the scheduler would
 * never look at — a button reporting success and changing nothing.
 */
final class FailedPlanRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'failed-recovery.example.com',
            'name' => 'Failed Recovery',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /** The button that was missing. A good card at `failed` can be charged by hand. */
    public function test_a_failed_plan_with_a_live_card_offers_charge_now(): void
    {
        $plan = $this->migratedFailedPlan();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionVisible('chargeNow');
    }

    /** No card, no button — the one case where hiding it is the truth. */
    public function test_a_failed_plan_with_no_usable_card_still_hides_it(): void
    {
        $plan = $this->migratedFailedPlan(withCard: false);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionHidden('chargeNow');
    }

    /** And a way back onto the schedule without charging this second. */
    public function test_a_failed_plan_offers_resume(): void
    {
        $plan = $this->migratedFailedPlan();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionVisible('resume');
    }

    /**
     * THE SILENT NO-OP THIS FIXES. A migrated member arrives with no charge date
     * at all, so "Resume" used to leave next_charge_at NULL — an ACTIVE plan the
     * scheduler would never pick up, and a success notification that meant nothing.
     */
    public function test_resuming_a_plan_with_no_charge_date_gives_it_one(): void
    {
        $plan = $this->migratedFailedPlan();
        $this->assertNull($plan->next_charge_at, 'the production shape: no clock');

        app(SubscriptionLifecycleService::class)->resume($plan);

        $fresh = $plan->fresh();
        $this->assertSame(PlanStatus::ACTIVE, $fresh->status);
        $this->assertSame(
            now()->startOfDay()->toDateString(),
            $fresh->next_charge_at->toDateString(),
            'an active plan with no clock is a plan nobody will ever charge',
        );
    }

    /**
     * A plan WE held for an unpaid cycle keeps the date it owes — the rule this
     * change must not have broken. Resuming is not forgiving last month.
     */
    public function test_a_plan_we_held_still_keeps_the_date_it_owes(): void
    {
        $owed = now()->subMonth()->startOfDay();

        $plan = $this->migratedFailedPlan();
        $plan->forceFill([
            'status' => PlanStatus::PAUSED->value,
            'next_charge_at' => $owed,
            'payment_failed_at' => now()->subWeek(),
        ])->save();

        app(SubscriptionLifecycleService::class)->resume($plan);

        $this->assertSame($owed->toDateString(), $plan->fresh()->next_charge_at->toDateString());
    }

    /** A cancelled plan stays a dead end — that transition is genuinely terminal. */
    public function test_a_cancelled_plan_offers_neither(): void
    {
        $plan = $this->migratedFailedPlan();
        $plan->forceFill(['status' => PlanStatus::CANCELLED->value])->save();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertActionHidden('chargeNow')
            ->assertActionHidden('resume');
    }

    /**
     * The production shape, exactly: status `failed` from the importer's status
     * map, no charge date, no hold stamp, no payment slot — and a live card with a
     * stored consent behind it.
     */
    private function migratedFailedPlan(bool $withCard = true): InstallmentPlan
    {
        $method = $withCard
            ? InstallmentPaymentMethod::create([
                'payplus_card_token_uid' => 'tok-migrated',
                'payplus_customer_uid' => 'cust-migrated',
                'card_brand' => 'visa',
                'card_last_four' => '9125',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ])
            : null;

        CustomerConsent::create([
            'shopify_customer_id' => 'legacy-uuid',
            'consent_context' => CustomerConsent::CONTEXT_RECURRING,
            'accepted_at' => now()->subYear(),
        ]);

        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-migrated-'.uniqid(),
            'customer_name' => 'דניאל צלניקר',
            'customer_email' => 'danieltsel@example.com',
            'shopify_customer_id' => 'legacy-uuid',
            'external_customer_id' => 'legacy-uuid',
            'import_key' => 'fdfadfe7-0bc7-43cf-b3bf-684a7fd443d7',
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::FAILED->value,
            'payment_method_id' => $method?->getKey(),
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 39,
            'currency' => 'ILS',
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            // The shape that matters: no clock, no hold stamp, no slots.
            'next_charge_at' => null,
            'payment_failed_at' => null,
            'meta' => ['item_title' => 'מנוי שיבולת'],
        ])->save();

        return $plan;
    }
}
