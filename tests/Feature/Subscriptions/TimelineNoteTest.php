<?php

namespace Tests\Feature\Subscriptions;

use App\Filament\Pages\CustomerDetail;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\PlatformContext;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "+ Add note" on the subscription page and on the customer page.
 *
 * A note is a merchant's remark pinned to a plan's timeline — it must carry the
 * author, land on the plan it was written about, and show up in the aggregated
 * customer timeline. From the customer page it can only ever land on one of
 * THAT customer's plans.
 */
final class TimelineNoteTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'notes.example.com',
            'name' => 'Notes',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::set($this->shop);
        $this->user = User::factory()->forShop($this->shop)->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_note_from_the_subscription_page_lands_on_its_timeline_with_the_author(): void
    {
        $plan = $this->plan('77');

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->callAction('addNote', ['note' => "התקשרתי, יעדכן כרטיס ביום ראשון\nשורה שנייה"]);

        $event = ActivityEvent::query()
            ->where('plan_id', $plan->getKey())
            ->where('kind', Timeline::KIND_ADMIN_NOTE)
            ->first();

        $this->assertNotNull($event);
        $this->assertStringContainsString('יעדכן כרטיס', $event->details['note']);
        $this->assertSame(PlatformContext::ADMIN_PREFIX.$this->user->getKey(), $event->actor);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertSee('יעדכן כרטיס');
    }

    public function test_a_note_from_the_customer_page_lands_on_the_chosen_plan_and_shows_there(): void
    {
        $first = $this->plan('77');
        $second = $this->plan('77');

        Livewire::test(CustomerDetail::class, ['customer' => '77'])
            ->callAction('addNote', ['plan_id' => $second->getKey(), 'note' => 'ביקש חשבונית על שם החברה']);

        $this->assertSame(0, ActivityEvent::query()->where('plan_id', $first->getKey())->where('kind', Timeline::KIND_ADMIN_NOTE)->count());
        $this->assertSame(1, ActivityEvent::query()->where('plan_id', $second->getKey())->where('kind', Timeline::KIND_ADMIN_NOTE)->count());

        Livewire::test(CustomerDetail::class, ['customer' => '77'])
            ->assertSee('ביקש חשבונית');
    }

    /** A plan id from outside this customer's set is refused — never honoured. */
    public function test_a_foreign_plan_id_is_refused_on_the_customer_page(): void
    {
        $this->plan('77');
        $this->plan('77');
        $stranger = $this->plan('99');

        Livewire::test(CustomerDetail::class, ['customer' => '77'])
            ->callAction('addNote', ['plan_id' => $stranger->getKey(), 'note' => 'should not land']);

        $this->assertSame(
            0,
            ActivityEvent::query()->where('plan_id', $stranger->getKey())->where('kind', Timeline::KIND_ADMIN_NOTE)->count(),
            'the stranger\'s plan must not receive the note',
        );
    }

    private function plan(string $customerId): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-note-'.uniqid('', true),
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'shopify_customer_id' => $customerId,
            'customer_name' => 'Customer '.$customerId,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 60,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
        ])->save();

        return $plan;
    }
}
