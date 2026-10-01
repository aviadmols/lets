<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Upsells\UpsellsQuery;
use App\Filament\Pages\Analytics;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\UpsellFixture;
use Tests\TestCase;

/**
 * Upsells › Sold: money that actually moved, net of refunds — after-purchase
 * token charges and account-area add-ons bought now; its share of everything
 * LETS collected; the compare delta in points; another shop never counted.
 */
final class UpsellsSoldTest extends TestCase
{
    use RefreshDatabase;
    use UpsellFixture;

    private Shop $shop;

    private Shop $other;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-29 12:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00'));
        $this->shop = $this->makeShop('us-a');
        $this->other = $this->makeShop('us-b');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function sold(Shop $shop, string $channel = UpsellsQuery::CHANNEL_ALL, bool $previous = false): array
    {
        $context = Context::default();
        $window = $previous ? $context->period->comparison() : $context->period;

        return Tenant::run($shop, fn (): array => (new UpsellsQuery($context, $channel))->sold($window));
    }

    public function test_sold_is_net_money_on_both_channels(): void
    {
        $this->upsellStory($this->shop);

        $s = $this->sold($this->shop);

        $this->assertSame(3, $s['items'], 'o1, o2 (refunded) and the bookmark purchase.');
        $this->assertSame(64.0, $s['revenue'], '40 + 0 (refunded) + 24.');
        $this->assertSame(3, $s['orders']);
        $this->assertSame(264.0, $s['collected'], '40 + 0 + 24 + the 200 renewal.');
        $this->assertSame(24.2, $s['contribution']);

        $this->assertSame(24.0, $this->sold($this->shop, UpsellsQuery::CHANNEL_ACCOUNT)['revenue']);
        $this->assertSame(40.0, $this->sold($this->shop, UpsellsQuery::CHANNEL_CHECKOUT)['revenue']);

        $before = $this->sold($this->shop, previous: true);
        $this->assertSame(40.0, $before['revenue']);
        $this->assertSame(100.0, $before['contribution'], 'In August the only money was o0.');
    }

    public function test_another_shops_rows_are_never_counted(): void
    {
        $this->upsellStory($this->shop);
        $this->upsellStory($this->other);
        $extra = $this->upsellOffer($this->other, 'Theirs', 500);
        $at = CarbonImmutable::parse('2026-09-20 10:00');
        $this->upsellEvent($this->other, $extra, 'charge_succeeded', $at, 'x1', 500, $this->upsellLedger($this->other, 500, $at));

        $this->assertSame(64.0, $this->sold($this->shop)['revenue']);
        $this->assertSame(264.0, $this->sold($this->shop)['collected']);
        $this->assertSame(564.0, $this->sold($this->other)['revenue']);
    }

    public function test_the_screen_renders_empty_and_with_data(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'upsells', 'sold')
            ->assertOk()
            ->assertSee(__('analytics/upsells_sold.kpi.contribution'))
            ->assertSee(__('analytics.empty.no_data_title'));

        $this->upsellStory($this->shop);
        Cache::flush();

        Livewire::test(Analytics::class)->call('go', 'upsells', 'sold')
            ->assertOk()
            ->assertSee('Mug')
            ->assertSee('Bookmark set')
            ->assertSee('24.2%')
            ->call('setGrain', UpsellsQuery::CHART_SOLD, 'daily')
            ->assertOk();
    }
}
