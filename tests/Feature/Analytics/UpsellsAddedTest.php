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
 * Upsells › Added: what customers said yes to, per channel — the after-purchase
 * accept at the offer's price, account-area acceptances that ADD (a switch is
 * not an upsell, and a switch's double Timeline row counts once), what of it
 * was billed to date, and another shop never counted.
 */
final class UpsellsAddedTest extends TestCase
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
        $this->shop = $this->makeShop('ua-a');
        $this->other = $this->makeShop('ua-b');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function added(Shop $shop, string $channel = UpsellsQuery::CHANNEL_ALL): array
    {
        $context = Context::default();

        return Tenant::run($shop, fn (): array => (new UpsellsQuery($context, $channel))->added($context->period));
    }

    public function test_added_counts_both_channels(): void
    {
        $this->upsellStory($this->shop);

        $a = $this->added($this->shop);

        // after-purchase: 3 accepts × 40; account area: 2 + 1 + 1 items, 24 + 19 + 45.
        $this->assertSame(7, $a['items']);
        $this->assertSame(208.0, $a['revenue']);
        $this->assertSame(5, $a['orders'], 'Three parent orders + two subscriptions.');
        $this->assertSame(64.0, $a['sold_to_date'], 'o1 40 + the bookmark 24; o2 was refunded; o4 was never charged here.');

        $flow = collect($a['profiles'])->firstWhere('channel', UpsellsQuery::CHANNEL_CHECKOUT);
        $this->assertSame(4, $flow['shown']);
        $this->assertSame(3, $flow['accepted']);
        $this->assertNull(collect($a['profiles'])->firstWhere('channel', UpsellsQuery::CHANNEL_ACCOUNT)['shown'], 'The account area records no impressions.');
    }

    public function test_the_channel_toggle_narrows_every_number(): void
    {
        $this->upsellStory($this->shop);

        $this->assertSame(120.0, $this->added($this->shop, UpsellsQuery::CHANNEL_CHECKOUT)['revenue']);
        $account = $this->added($this->shop, UpsellsQuery::CHANNEL_ACCOUNT);
        $this->assertSame(88.0, $account['revenue']);
        $this->assertSame(['Audiobook', 'Bookmark set', 'Add the magazine'], collect($account['items_table'])->sortByDesc('revenue')->reverse()->pluck('title')->values()->all());
    }

    public function test_another_shops_rows_are_never_counted(): void
    {
        $this->upsellStory($this->shop);
        $this->upsellStory($this->other);
        $extra = $this->upsellOffer($this->other, 'Theirs', 500);
        $this->upsellEvent($this->other, $extra, 'accepted', CarbonImmutable::parse('2026-09-20 10:00'), 'x1');

        $this->assertSame(208.0, $this->added($this->shop)['revenue']);
        $this->assertSame(708.0, $this->added($this->other)['revenue']);
    }

    public function test_the_screen_renders_empty_and_with_data(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'upsells', 'added')
            ->assertOk()
            ->assertSee(__('analytics/upsells_added.performance.title'))
            ->assertSee(__('analytics/upsells_added.by_channel.untracked'))
            ->assertSee(__('analytics.empty.no_data_title'));

        $this->upsellStory($this->shop);
        Cache::flush();

        Livewire::test(Analytics::class)->call('go', 'upsells', 'added')
            ->assertOk()
            ->assertSee('Summer boost')
            ->assertSee('Bookmark set')
            ->assertSee('rc-donut__slice', false)
            ->call('setOption', 'channel', UpsellsQuery::CHANNEL_ACCOUNT)
            ->assertOk()
            ->assertDontSee('Summer boost');
    }
}
