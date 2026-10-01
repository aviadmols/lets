<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Products\ProductsChurnQuery;
use App\Filament\Pages\Analytics;
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
 * Products › Churn & retention: churn per product (voluntary + payment lapse),
 * a switch is NOT churn, reasons from the cancel event, the cancellation rate
 * against the units held at the period's start, the not-tracked cancellation
 * flow — and another shop never counted.
 */
final class ProductsChurnTest extends TestCase
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
        $this->shop = $this->makeShop('pc-a');
        $this->other = $this->makeShop('pc-b');
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

    private function fixture(Shop $shop): void
    {
        $born = $this->d('2026-05-01');
        $p1 = ['external_product_id' => 'p1'];
        $p2 = ['external_product_id' => 'p2'];

        $this->plan($shop, 'a', 50, createdAt: $born, attributes: $p1);

        $b = $this->plan($shop, 'b', 50, status: 'cancelled', createdAt: $born, attributes: $p1);
        $this->moveWithReason($b, 'active', 'cancelled', $this->d('2026-09-10'), 'Too expensive');

        $c = $this->plan($shop, 'c', 40, status: 'failed', createdAt: $born, attributes: $p2);
        $this->moveWithReason($c, 'active', 'failed', $this->d('2026-09-12'), null);

        $swap = $this->plan($shop, 'd', 40, status: 'cancelled', createdAt: $born, attributes: $p2 + ['meta' => ['account_offer_replaced_by' => 'PLAN-Z']]);
        $this->moveWithReason($swap, 'active', 'cancelled', $this->d('2026-09-15'), 'account_offer:3');

        $e = $this->plan($shop, 'e', 50, status: 'cancelled', createdAt: $born, attributes: $p1);
        $this->moveWithReason($e, 'active', 'cancelled', $this->d('2026-09-18'), null);
    }

    private function query(Shop $shop, string $measure = ProductsChurnQuery::MEASURE_MRR): array
    {
        return Tenant::run($shop, fn (): array => (new ProductsChurnQuery(Context::default(), $measure))->get());
    }

    public function test_churn_by_product_excludes_swaps(): void
    {
        $this->fixture($this->shop);

        $q = $this->query($this->shop);

        $this->assertSame(['mrr' => 140.0, 'qty' => 3], $q['churned']);
        $this->assertEqualsWithDelta(100.0, array_sum($q['trend']['products']['p1']), 0.01);
        $this->assertEqualsWithDelta(40.0, array_sum($q['trend']['products']['p2']), 0.01, 'The swap (40) is not churn.');

        $units = $this->query($this->shop, ProductsChurnQuery::MEASURE_QUANTITY);
        $this->assertEqualsWithDelta(2.0, array_sum($units['trend']['products']['p1']), 0.01);
    }

    public function test_reasons_come_from_the_cancel_event(): void
    {
        $this->fixture($this->shop);

        $reasons = array_column($this->query($this->shop)['reasons'], null, 'reason');

        $this->assertSame(['reason' => 'Too expensive', 'qty' => 1, 'mrr' => 50.0], $reasons['Too expensive']);
        $this->assertSame(1, $reasons[ProductsChurnQuery::REASON_PAYMENT_FAILED]['qty']);
        $this->assertSame(1, $reasons[ProductsChurnQuery::REASON_NONE]['qty']);
        $this->assertArrayNotHasKey('account_offer:3', $reasons, 'A switch is not a cancellation reason.');
    }

    public function test_cancellation_rate_is_against_the_units_at_the_start(): void
    {
        $this->fixture($this->shop);

        $rows = array_column($this->query($this->shop)['table'], null, 'pk');

        $this->assertSame(1, $rows['p1']['subscribed']);
        $this->assertSame(3, $rows['p1']['start']);
        $this->assertSame(2, $rows['p1']['cancelled']);
        $this->assertSame(66.7, $rows['p1']['rate']);
        $this->assertSame(2, $rows['p2']['start'], 'The swapped unit was held at the start too.');
        $this->assertSame(50.0, $rows['p2']['rate']);
    }

    public function test_another_shops_rows_are_never_counted(): void
    {
        $this->fixture($this->shop);
        $this->fixture($this->other);
        $more = $this->plan($this->other, 'x', 500, status: 'cancelled', createdAt: $this->d('2026-05-01'), attributes: ['external_product_id' => 'p1']);
        $this->moveWithReason($more, 'active', 'cancelled', $this->d('2026-09-11'), 'Too expensive');

        $this->assertSame(['mrr' => 140.0, 'qty' => 3], $this->query($this->shop)['churned']);
        $this->assertSame(['mrr' => 640.0, 'qty' => 4], $this->query($this->other)['churned']);
    }

    public function test_the_screen_renders_empty_and_with_data_and_flags_the_flow_not_tracked(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'products', 'churn')
            ->assertOk()
            ->assertSee(__('analytics/products_churn.trend.title'))
            ->assertSee(__('analytics.empty.not_tracked_title'))
            ->assertSee(__('analytics/products_churn.flow.body'));

        $this->fixture($this->shop);
        Cache::flush();

        Livewire::test(Analytics::class)->call('go', 'products', 'churn')
            ->assertOk()
            ->assertSee('Too expensive')
            ->assertSee(__('analytics/products_churn.reasons.payment_failed'))
            ->assertSee('rc-chart__line', false)
            ->call('setOption', 'measure', 'quantity')
            ->assertOk()
            ->assertSee(__('analytics/products_churn.reasons.caption_quantity'));
    }
}
