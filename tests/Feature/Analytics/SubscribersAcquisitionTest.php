<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Subscribers\AcquisitionQuery;
use App\Domain\Analytics\Subscribers\ActiveBook;
use App\Filament\Pages\Analytics;
use App\Models\Product;
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
 * Subscribers › Acquisition: acquired = a person's FIRST subscription arriving,
 * 0-day churn = gone the day they came, product cards read the new
 * subscriptions (checkout revenue = first realised charge, net of refunds),
 * another shop is never counted, and the screen renders empty and full with
 * the not-tracked cards in place.
 */
final class SubscribersAcquisitionTest extends TestCase
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
        return Tenant::run($shop, fn (): array => (new AcquisitionQuery(Context::default()))->get());
    }

    private function fixture(Shop $shop): void
    {
        Tenant::run($shop, fn () => Product::query()->forceCreate([
            'shop_id' => $shop->getKey(), 'source' => 'woocommerce', 'external_id' => 'p1', 'title' => 'Novel', 'status' => 'active',
        ]));

        // Customer 1: first subscription in the window (acquired) + a second one (not a new subscriber).
        $a = $this->plan($shop, '1', 50, createdAt: CarbonImmutable::parse('2026-09-10 10:00'), attributes: ['external_product_id' => 'p1']);
        $this->charge($a, 50, CarbonImmutable::parse('2026-09-10 10:05'));
        $this->charge($a, 50, CarbonImmutable::parse('2026-09-25 10:05'));
        $this->plan($shop, '1', 30, createdAt: CarbonImmutable::parse('2026-09-15 10:00'), attributes: ['external_product_id' => 'p1']);

        // Customer 2: acquired and gone the same day (0-day churn), partly refunded.
        $c = $this->plan($shop, '2', 40, status: 'cancelled', createdAt: CarbonImmutable::parse('2026-09-12 09:00'), attributes: ['external_product_id' => 'p2']);
        $this->charge($c, 40, CarbonImmutable::parse('2026-09-12 09:01'), refunded: 10);
        $this->move($c, 'active', 'cancelled', CarbonImmutable::parse('2026-09-12 15:00'));

        // Customer 3: a subscriber long before the window.
        $this->plan($shop, '3', 50, createdAt: CarbonImmutable::parse('2026-06-01'));
    }

    public function test_acquired_subscribers_zero_day_churn_and_breakdowns(): void
    {
        $this->fixture($this->shop);

        $q = $this->query($this->shop);

        $this->assertSame(2, $q['acquired']['value'], 'Customers 1 and 2 became subscribers; the second plan of customer 1 is not a new subscriber.');
        $this->assertSame(0, $q['acquired']['previous']);
        $this->assertSame(1, $q['zero_day']['value']);
        $this->assertSame(50.0, $q['zero_day']['share']);

        $products = collect($q['by_product'])->keyBy('key');
        $this->assertSame(2, $products['p1']['quantity'], 'Both new subscriptions of p1 count as units.');
        $this->assertSame('Novel', $products['p1']['label']);
        $this->assertEqualsWithDelta(50.0, $products['p1']['revenue'], 0.001, 'Checkout = the first charge only.');
        $this->assertEqualsWithDelta(30.0, $products['p2']['revenue'], 0.001, 'Net of the refund.');

        $this->assertCount(1, $q['plan_frequency']);
        $row = $q['plan_frequency'][0];
        $this->assertSame(ActiveBook::PLAN_NONE, $row['plan_key']);
        $this->assertSame('m1', $row['freq']);
        $this->assertSame(2, $row['subscribers']);
        $this->assertEqualsWithDelta(90.0, $row['mrr'], 0.001);
        $this->assertSame(1, $row['zero_day']);
        $this->assertSame(2, array_sum($q['trend']['values']), 'The trend sums to the KPI.');
    }

    public function test_another_shops_rows_are_never_counted(): void
    {
        $this->fixture($this->other);
        $this->plan($this->other, '9', 70, createdAt: CarbonImmutable::parse('2026-09-20'));
        $this->plan($this->shop, '1', 50, createdAt: CarbonImmutable::parse('2026-09-21'));

        $mine = $this->query($this->shop);
        $theirs = $this->query($this->other);

        $this->assertSame(1, $mine['acquired']['value']);
        $this->assertSame(0, $mine['zero_day']['value']);
        $this->assertSame([], array_values(array_filter($mine['by_product'], static fn (array $p): bool => $p['revenue'] > 0)));
        $this->assertSame(3, $theirs['acquired']['value']);
    }

    public function test_the_screen_renders_empty_and_with_data(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'subscribers', 'acquisition')
            ->assertOk()
            ->assertSee(__('analytics/subscribers_acquisition.kpi.acquired'))
            ->assertSee(__('analytics.empty.not_tracked_title'))
            ->assertSee(__('analytics.empty.no_data_title'));

        $this->fixture($this->shop);
        Cache::flush(); // the empty render above cached its numbers

        Livewire::test(Analytics::class)->call('go', 'subscribers', 'acquisition')
            ->assertOk()
            ->assertSee('Novel')
            ->assertSee('rc-hbar__fill', false)
            ->assertSee(__('analytics/subscribers_acquisition.table.title'))
            ->assertSee(__('analytics/subscribers_acquisition.order_number.not_tracked'));
    }
}
