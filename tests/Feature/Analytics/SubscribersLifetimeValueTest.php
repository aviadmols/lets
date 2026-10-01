<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Subscribers\LifetimeValueQuery;
use App\Filament\Pages\Analytics;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsLedger;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Subscribers › Lifetime value: LTV = subscription revenue realised ÷ people
 * who ever subscribed (one human on several plans or both rails is one
 * person; a plan that never went live is nobody), walked to the period end
 * and to the comparison end; another shop is never counted; the screen
 * renders empty and full with the segment cards not tracked.
 */
final class SubscribersLifetimeValueTest extends TestCase
{
    use BuildsLedger;
    use BuildsSubscriptions;
    use RefreshDatabase;

    private Shop $shop;

    private Shop $other;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-29 12:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00'));
        $this->shop = $this->makeShop('a');
        $this->other = $this->makeShop('b');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function query(Shop $shop): array
    {
        return Tenant::run($shop, fn (): array => (new LifetimeValueQuery(Context::default()))->get());
    }

    private function fixture(Shop $shop): void
    {
        $at = static fn (string $d): CarbonImmutable => CarbonImmutable::parse($d.' 10:00');

        // Customer 1: two plans, one person.
        $a = $this->plan($shop, '1', 50, createdAt: $at('2026-06-01'));
        foreach (['2026-06-01', '2026-07-01', '2026-09-01'] as $d) {
            $this->charge($a, 50, $at($d));
        }
        $b = $this->plan($shop, '1', 30, createdAt: $at('2026-09-10'));
        $this->charge($b, 30, $at('2026-09-10'), refunded: 10);

        // Never live: waiting for a first payment, and a draft that was cancelled.
        $this->plan($shop, '2', 50, status: 'awaiting_first_payment', createdAt: $at('2026-09-20'));
        $this->plan($shop, '3', 50, status: 'cancelled', createdAt: $at('2026-09-21'));

        // Customer 4: cancelled, but was charged — a subscriber who left.
        $e = $this->plan($shop, '4', 40, status: 'cancelled', createdAt: $at('2026-08-15'));
        $this->charge($e, 40, $at('2026-08-15'));

        // Customer 5: Shopify rail.
        $x = $this->contract($shop, '5', 60, createdAt: $at('2026-08-20'));
        $this->attempt($x, $at('2026-09-15'));
    }

    public function test_ltv_is_revenue_over_people_who_ever_subscribed(): void
    {
        $this->fixture($this->shop);

        $q = $this->query($this->shop);

        $this->assertSame(3, $q['now']['customers'], 'Customers 1, 4 and 5; plans that never went live are nobody.');
        $this->assertSame(6, $q['now']['orders']);
        $this->assertSame(270.0, $q['now']['revenue']);
        $this->assertSame(90.0, $q['now']['ltv']);
        $this->assertSame(2.0, $q['now']['orders_per_customer']);

        $this->assertSame(3, $q['previous']['customers']);
        $this->assertSame(46.67, $q['previous']['ltv']);
        $this->assertSame(92.8, $q['ltv_delta']);
        $this->assertSame(90.0, end($q['series']['values']), 'The trend ends on the headline.');
    }

    public function test_another_shops_rows_are_never_counted(): void
    {
        $this->fixture($this->other);
        $mine = $this->plan($this->shop, '1', 10, createdAt: CarbonImmutable::parse('2026-09-01'));
        $this->charge($mine, 10, CarbonImmutable::parse('2026-09-01 10:00'));

        $q = $this->query($this->shop);

        $this->assertSame(1, $q['now']['customers']);
        $this->assertSame(10.0, $q['now']['ltv']);
        $this->assertSame(90.0, $this->query($this->other)['now']['ltv']);
    }

    public function test_the_screen_renders_empty_and_with_data(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'subscribers', 'lifetime_value')
            ->assertOk()
            ->assertSee(__('analytics/subscribers_lifetime_value.summary.empty'))
            ->assertSee(__('analytics/subscribers_lifetime_value.segments.not_tracked'));

        $this->fixture($this->shop);
        Cache::flush();

        Livewire::test(Analytics::class)->call('go', 'subscribers', 'lifetime_value')
            ->assertOk()
            ->assertSee(__('analytics/subscribers_lifetime_value.summary.lead_before'))
            ->assertSee('₪90', false)
            ->assertSee(__('analytics/subscribers_lifetime_value.segment.non_subscribers'))
            ->assertSee('rc-chart__line', false);
    }
}
