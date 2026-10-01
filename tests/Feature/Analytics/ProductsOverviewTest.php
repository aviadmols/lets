<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Products\ProductMovements;
use App\Domain\Analytics\Products\ProductsOverviewQuery;
use App\Filament\Pages\Analytics;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsProductsAndUpsells;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Products › Overview: the active book per product, acquisitions by the status
 * they hold today (a switch is "swapped", not "cancelled"), checkout = a plan's
 * first settled charge, and another shop's rows never counted.
 */
final class ProductsOverviewTest extends TestCase
{
    use BuildsProductsAndUpsells;
    use BuildsSubscriptions;
    use RefreshDatabase;

    private Shop $shop;

    private Shop $other;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-29 12:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00'));
        $this->shop = $this->makeShop('po-a');
        $this->other = $this->makeShop('po-b');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    /** Builds the reference fixture under $shop. */
    private function fixture(Shop $shop): void
    {
        $this->product($shop, 'p1', 'Monthly novel');
        $this->product($shop, 'p2', 'Quarterly box');

        $old = $this->plan($shop, 'c1', 100, createdAt: $this->d('2026-06-01'), attributes: ['external_product_id' => 'p1']);
        $this->ledger($old, 100, $this->d('2026-06-01 10:00'));          // its checkout, long ago
        $this->ledger($old, 100, $this->d('2026-09-10 10:00'));          // recurring, in the window

        $new = $this->plan($shop, 'c2', 100, createdAt: $this->d('2026-09-05'), attributes: ['external_product_id' => 'p1']);
        $this->ledger($new, 100, $this->d('2026-09-05 10:00'));          // checkout, in the window

        $gone = $this->plan($shop, 'c3', 60, status: 'cancelled', createdAt: $this->d('2026-09-06'), attributes: ['external_product_id' => 'p2']);
        $this->move($gone, 'active', 'cancelled', $this->d('2026-09-20'));

        $swapped = $this->plan($shop, 'c4', 60, status: 'cancelled', createdAt: $this->d('2026-05-01'),
            attributes: ['external_product_id' => 'p2', 'meta' => ['account_offer_replaced_by' => 'PLAN-X']]);
        $this->moveWithReason($swapped, 'active', 'cancelled', $this->d('2026-09-21'), 'account_offer:5');
    }

    private function query(Shop $shop): array
    {
        return Tenant::run($shop, fn (): array => (new ProductsOverviewQuery(Context::default()))->get());
    }

    public function test_product_numbers_from_a_hand_built_fixture(): void
    {
        $this->fixture($this->shop);

        $q = $this->query($this->shop);
        $products = array_column($q['products'], null, 'pk');

        $this->assertSame(2, $products['p1']['subscribers']);
        $this->assertSame('Monthly novel', $products['p1']['title'], 'Named from the catalog.');
        $this->assertSame(0, $products['p2']['subscribers']);

        // Acquired in the window, by status today: c2 active (p1), c3 cancelled (p2).
        $this->assertEquals(1, $q['acquired']['p1'][ProductMovements::STATUS_ACTIVE]);
        $this->assertEquals(1, $q['acquired']['p2'][ProductMovements::STATUS_CANCELLED]);

        // Checkout = the plan's FIRST settled charge: only c2's, not c1's renewal.
        $this->assertSame(['revenue' => 100.0, 'orders' => 1], $q['checkout']['p1']);

        $rows = array_column($q['table'], null, 'pk');
        $this->assertSame(1, $rows['p1']['new']);
        $this->assertSame(2, $rows['p1']['active']);
        $this->assertSame(200.0, $rows['p1']['revenue']);
        $this->assertSame(100.0, $rows['p1']['per_payer']);
        $this->assertSame(1, $rows['p2']['cancelled']);
        $this->assertSame(1, $rows['p2']['swapped'], 'An account-offer switch is a swap, not a cancellation.');
    }

    public function test_another_shops_rows_are_never_counted(): void
    {
        $this->fixture($this->shop);
        $this->fixture($this->other);
        $extra = $this->plan($this->other, 'c9', 999, createdAt: $this->d('2026-09-07'), attributes: ['external_product_id' => 'p1']);
        $this->ledger($extra, 999, $this->d('2026-09-07 10:00'));

        $rows = array_column($this->query($this->shop)['table'], null, 'pk');

        $this->assertSame(2, $rows['p1']['active']);
        $this->assertSame(200.0, $rows['p1']['revenue']);

        $theirs = array_column($this->query($this->other)['table'], null, 'pk');
        $this->assertSame(3, $theirs['p1']['active']);
    }

    public function test_no_tenant_means_nothing(): void
    {
        $this->fixture($this->shop);
        Tenant::clear();

        $q = (new ProductsOverviewQuery(Context::default()))->get();

        $this->assertSame([], $q['table']);
        $this->assertFalse($q['has_data']);
    }

    public function test_the_screen_renders_empty_and_with_data(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'products', 'overview')
            ->assertOk()
            ->assertSee(__('analytics/products_overview.by_product.title'))
            ->assertSee(__('analytics.empty.no_data_title'));

        $this->fixture($this->shop);
        Cache::flush(); // the first render cached the empty shop's numbers

        Livewire::test(Analytics::class)->call('go', 'products', 'overview')
            ->assertOk()
            ->assertSee('Monthly novel')
            ->assertSee('rc-donut__slice', false)
            ->assertSee(__('analytics/products_overview.status.swapped'))
            ->call('setOption', 'acquisition', 'quantity')
            ->assertOk();
    }
}
