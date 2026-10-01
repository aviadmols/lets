<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Products\ProductsRevenueQuery;
use App\Filament\Pages\Analytics;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsProductsAndUpsells;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Products › Revenue: net of refunds, checkout vs recurring, upsell and failed
 * charges excluded, a Shopify contract split across its lines by units and
 * counted as ONE order, the compare delta — and another shop never counted.
 */
final class ProductsRevenueTest extends TestCase
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
        $this->shop = $this->makeShop('pr-a');
        $this->other = $this->makeShop('pr-b');
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

    private function fixture(Shop $shop, bool $contract = true): void
    {
        $this->product($shop, 'p1', 'Monthly novel');
        $one = $this->plan($shop, 'c1', 50, createdAt: $this->d('2026-08-20'), attributes: ['external_product_id' => 'p1']);
        $this->ledger($one, 50, $this->d('2026-08-20 10:00'));                                  // checkout, compare window
        $this->ledger($one, 50, $this->d('2026-09-10 10:00'));                                  // recurring
        $this->ledger($one, 50, $this->d('2026-09-15 10:00'), refunded: 20);                    // recurring, net 30
        $this->ledger($one, 70, $this->d('2026-09-16 10:00'), PaymentLedger::STATUS_FAILED);    // not money

        $two = $this->plan($shop, 'c2', 60, createdAt: $this->d('2026-09-12'), attributes: ['external_product_id' => 'p1']);
        $this->ledger($two, 60, $this->d('2026-09-12 10:00'));                                  // checkout
        $this->ledger($two, 999, $this->d('2026-09-12 10:05'), context: PaymentLedger::CONTEXT_UPSELL); // an upsell, not subscription revenue

        if ($contract) {
            $c = $this->withLines($this->contract($shop, '77', 80), [
                ['product_id' => 'gid://shopify/Product/p1', 'quantity' => 1],
                ['product_id' => 'gid://shopify/Product/p2', 'quantity' => 3],
            ]);
            $this->billed($c, $this->d('2026-09-20 10:00'));
        }
    }

    private function query(Shop $shop): array
    {
        return Tenant::run($shop, fn (): array => (new ProductsRevenueQuery(Context::default()))->get());
    }

    public function test_revenue_splits_checkout_and_recurring_net_of_refunds(): void
    {
        $this->fixture($this->shop);

        $q = $this->query($this->shop);
        $k = $q['kpis'];

        // Plans: 50 + 30 recurring, 60 checkout. Contract: 80 recurring.
        $this->assertSame(220.0, $k['revenue']['value']);
        $this->assertSame(60.0, $k['checkout']['value']);
        $this->assertSame(160.0, $k['recurring']['value']);
        $this->assertSame(4, $k['orders']['value'], 'A two-line contract renewal is ONE order.');

        // Compare window: c1's checkout, 50.
        $this->assertSame(50.0, $k['revenue']['previous']);
        $this->assertSame(340.0, $k['revenue']['delta']);

        $byProduct = array_column($q['products'], null, 'pk');
        $this->assertSame(160.0, $byProduct['p1']['revenue'], '140 from plans + a quarter of the contract.');
        $this->assertSame(60.0, $byProduct['p2']['revenue'], 'Three quarters of the contract, by units.');
        $this->assertSame(3, $byProduct['p2']['units']);
        $this->assertSame(1, $byProduct['p1']['checkout_orders']);
    }

    public function test_another_shops_rows_are_never_counted(): void
    {
        $this->fixture($this->shop, contract: false);
        $this->fixture($this->other);

        $this->assertSame(140.0, $this->query($this->shop)['kpis']['revenue']['value']);
        $this->assertSame(220.0, $this->query($this->other)['kpis']['revenue']['value']);
    }

    public function test_a_product_filter_leaves_contracts_out(): void
    {
        $this->fixture($this->shop);
        $context = new Context(\App\Domain\Analytics\Period::default(), \App\Domain\Analytics\Filters::fromInput(['products' => ['p1']]));

        $q = Tenant::run($this->shop, fn (): array => (new ProductsRevenueQuery($context))->get());

        $this->assertSame(140.0, $q['kpis']['revenue']['value']);
    }

    public function test_the_screen_renders_empty_and_with_data(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'products', 'revenue')
            ->assertOk()
            ->assertSee(__('analytics/products_revenue.trend.title'))
            ->assertSee(__('analytics.empty.no_data_title'));

        $this->fixture($this->shop);
        Cache::flush();

        Livewire::test(Analytics::class)->call('go', 'products', 'revenue')
            ->assertOk()
            ->assertSee('Monthly novel')
            ->assertSee('rc-chart__line', false)
            ->assertSee(__('analytics/products_revenue.type.checkout'))
            ->call('setGrain', ProductsRevenueQuery::CHART_TREND, 'daily')
            ->assertOk();
    }
}
