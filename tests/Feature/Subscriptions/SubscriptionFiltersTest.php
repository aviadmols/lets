<?php

namespace Tests\Feature\Subscriptions;

use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Finding the subscriptions that need a human, and taking them away as a file.
 *
 * There WAS a filter here for expired cards. It was removed, and the reason is
 * worth keeping: the expiry we store is written once when the card is vaulted —
 * or copied from a migration file — and never again, while the token keeps
 * working through a bank's renewal. Hundreds of cards our data called expired
 * were charging perfectly, so the filter sorted by a stale label rather than by
 * anything true. A charge that FAILED has no such ambiguity, and that is what
 * replaced it.
 */
final class SubscriptionFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'filters.example.com',
            'name' => 'Filters',
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

    public function test_the_charge_date_filter_narrows_to_a_single_day(): void
    {
        $today = $this->plan('today', now()->startOfDay());
        $tomorrow = $this->plan('tomorrow', now()->addDay()->startOfDay());
        $never = $this->plan('never', null);

        Livewire::test(ListSubscriptions::class)
            ->filterTable('next_charge_at', [
                'from' => now()->toDateString(),
                'until' => now()->toDateString(),
            ])
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$tomorrow, $never]);
    }

    public function test_the_frequency_filter_finds_the_yearly_plans(): void
    {
        $monthly = $this->plan('monthly');
        $yearly = $this->plan('yearly');
        $yearly->forceFill(['billing_frequency' => BillingFrequency::YEARLY->value])->save();

        Livewire::test(ListSubscriptions::class)
            ->filterTable('billing_frequency', BillingFrequency::YEARLY->value)
            ->assertCanSeeTableRecords([$yearly])
            ->assertCanNotSeeTableRecords([$monthly]);
    }

    /** Matched on the product ID — two plans can spell the same product differently. */
    public function test_the_product_filter_matches_on_the_platform_id(): void
    {
        $coffee = $this->plan('coffee');
        $coffee->forceFill(['external_product_id' => 'prod-coffee', 'meta' => ['item_title' => 'Coffee box']])->save();

        $tea = $this->plan('tea');
        $tea->forceFill(['external_product_id' => 'prod-tea', 'meta' => ['item_title' => 'Tea box']])->save();

        Livewire::test(ListSubscriptions::class)
            ->filterTable('external_product_id', 'prod-coffee')
            ->assertCanSeeTableRecords([$coffee])
            ->assertCanNotSeeTableRecords([$tea]);
    }

    /**
     * A migration file's "description" was the product CODE ("2675 שנתי"). A
     * code is not a name: when the catalog knows the product, its name wins.
     */
    public function test_a_code_shaped_title_yields_to_the_catalog_name(): void
    {
        $product = new \App\Models\Product;
        $product->forceFill([
            'shop_id' => $this->shop->getKey(),
            'source' => \App\Models\Product::SOURCE_WOOCOMMERCE,
            'external_id' => '2675',
            'title' => 'מנוי שיבולת פלוס',
            'status' => \App\Models\Product::STATUS_ACTIVE,
        ])->save();

        $coded = $this->plan('coded');
        $coded->forceFill(['external_product_id' => '2675', 'meta' => ['item_title' => '2675  שנתי']])->save();

        $named = $this->plan('named');
        $named->forceFill(['external_product_id' => '2675', 'meta' => ['item_title' => 'מנוי לנסיון']])->save();

        // 26750 is not 2675 — the code must match whole, not as a prefix.
        $other = $this->plan('other');
        $other->forceFill(['external_product_id' => '26750', 'meta' => ['item_title' => '26750 מיוחד']])->save();

        $this->assertSame('מנוי שיבולת פלוס', $coded->fresh()->productTitle());
        $this->assertSame('מנוי לנסיון', $named->fresh()->productTitle(), 'a real name is kept');
        $this->assertSame('26750 מיוחד', $other->fresh()->productTitle(), 'no catalog row → the title stands');
    }

    /**
     * The tab that replaced the card one. A plan that failed LONG ago and has
     * billed cleanly since is not failing — only the latest attempt counts, or
     * the tab fills with history and buries the ones that need work today.
     */
    public function test_the_failing_tab_reads_only_the_latest_attempt(): void
    {
        $recovered = $this->plan('recovered');
        $this->payment($recovered, 1, PaymentStatus::FAILED);
        $this->payment($recovered, 2, PaymentStatus::SUCCEEDED);

        $failing = $this->plan('failing');
        $this->payment($failing, 1, PaymentStatus::SUCCEEDED);
        $this->payment($failing, 2, PaymentStatus::FAILED);

        $terminal = $this->plan('terminal');
        $terminal->forceFill(['status' => PlanStatus::FAILED->value])->save();

        Livewire::test(ListSubscriptions::class)
            ->set('activeTab', 'failing')
            ->assertCanSeeTableRecords([$failing, $terminal])
            ->assertCanNotSeeTableRecords([$recovered]);
    }

    /** The file is what the screen shows — not the whole book. */
    public function test_the_export_carries_the_active_filter(): void
    {
        $yearly = $this->plan('yearly-export');
        $yearly->forceFill(['billing_frequency' => BillingFrequency::YEARLY->value])->save();
        $this->plan('monthly-export');

        $component = Livewire::test(ListSubscriptions::class)
            ->filterTable('billing_frequency', BillingFrequency::YEARLY->value);

        $ids = $component->instance()->getFilteredTableQuery()->pluck('id')->all();

        $this->assertSame([$yearly->id], $ids, 'the export query is the filtered query');

        $component->callAction('export')->assertHasNoActionErrors();
    }

    // === Helpers ===

    private function plan(string $ref, mixed $nextCharge = null): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-'.$ref,
            'customer_name' => $ref,
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 50,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'next_charge_at' => $nextCharge,
        ])->save();

        return $plan;
    }

    private function payment(InstallmentPlan $plan, int $sequence, PaymentStatus $status): void
    {
        $payment = new InstallmentPayment;
        $payment->forceFill([
            'shop_id' => $this->shop->getKey(),
            'plan_id' => $plan->getKey(),
            'sequence' => $sequence,
            'amount' => 50,
            'currency' => 'ILS',
            'status' => $status->value,
        ])->save();
    }
}
