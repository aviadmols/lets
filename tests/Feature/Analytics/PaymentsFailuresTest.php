<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Payments\DeclineReason;
use App\Domain\Analytics\Payments\PaymentJourneys;
use App\Domain\Analytics\Payments\PaymentsFailuresQuery;
use App\Filament\Pages\Analytics;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsPayments;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Payments › Failures: failed FIRST attempts on both rails, their fate, the
 * split by source and by decline bucket — and another shop's failures never
 * appear.
 */
final class PaymentsFailuresTest extends TestCase
{
    use BuildsPayments;
    use BuildsSubscriptions;
    use RefreshDatabase;

    private Shop $shop;

    private Shop $other;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-29 12:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00'));
        $this->shop = $this->makeShop('fail-a');
        $this->other = $this->makeShop('fail-b');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function failures(): array
    {
        return Tenant::run($this->shop, fn (): array => (new PaymentsFailuresQuery(Context::default()))->get());
    }

    public function test_failures_by_source_and_reason(): void
    {
        $this->septemberStory($this->shop);
        $this->septemberStory($this->other);

        $q = $this->failures();
        $f = $q['failures'];

        $this->assertSame(5, $f['failures'], 'P2, P3, P4, P5 + the Shopify decline.');
        $this->assertSame(7, $f['attempts']);
        $this->assertEqualsWithDelta(71.4, $f['rate'], 0.05);
        $this->assertSame(2, $f['recovered']);
        $this->assertSame(1, $f['under']);
        $this->assertSame(2, $f['lost']);
        $this->assertSame(4, $f['entered']);

        $this->assertSame([PaymentJourneys::SOURCE_PAYPLUS => 4, PaymentJourneys::SOURCE_SHOPIFY => 1], $q['by_source']);
        $this->assertSame(3, $q['by_reason'][DeclineReason::INSUFFICIENT_FUNDS], 'Two PayPlus + Shopify\'s insufficient_funds.');
        $this->assertSame(2, $q['by_reason'][DeclineReason::BLOCKED]);
        $this->assertSame(5, (int) array_sum(array_map('array_sum', $q['series']['reasons'])));
    }

    public function test_compare_delta(): void
    {
        $this->septemberStory($this->shop);
        $old = $this->plan($this->shop, '77', 20);
        $this->charge($old, 20, [[CarbonImmutable::parse('2026-08-10 09:00'), 'fail', self::DECLINE_FUNDS]], 'failed');

        $this->assertEqualsWithDelta(400.0, $this->failures()['delta'], 0.01);
    }

    public function test_no_failures_is_the_empty_state(): void
    {
        $this->septemberStory($this->other);

        $q = $this->failures();

        $this->assertFalse($q['has_data']);
        $this->assertSame(0, $q['failures']['failures']);
    }

    public function test_screen_renders_empty_and_with_data(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'payments', 'failures')
            ->assertOk()
            ->assertSee(__('analytics/payments_failures.total.title'))
            ->assertSee(__('analytics.empty.no_data_title'));

        Cache::flush();
        $this->septemberStory($this->shop);

        Livewire::test(Analytics::class)->call('go', 'payments', 'failures')
            ->assertOk()
            ->assertSee(__('analytics/payments_failures.reason.insufficient_funds'))
            ->assertSee(__('analytics/payments_overview.source.shopify'))
            ->call('setGrain', 'failures_over_time', 'daily')
            ->assertOk();
    }
}
