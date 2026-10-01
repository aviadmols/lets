<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Cancellations\RiskQuery;
use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Filament\Pages\Analytics;
use App\Filament\Pages\Analytics\Screens\CancellationsRisk;
use App\Livewire\Analytics\RiskTable;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsLedger;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Cancellations › Risk analysis: every live subscription scored in SQL from
 * successive failures, lapsed billing, card expiry, short history and success
 * %; the live table searches, filters, sorts and pages; the CSV is
 * formula-safe; and another shop's subscriptions never appear — in the KPIs,
 * the table or either export.
 */
final class CancellationsRiskTest extends TestCase
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

    private function card(Shop $shop, int $month, int $year, string $status = 'active'): InstallmentPaymentMethod
    {
        $m = new InstallmentPaymentMethod();
        $m->forceFill(['shop_id' => $shop->getKey(), 'exp_month' => $month, 'exp_year' => $year, 'status' => $status, 'card_last_four' => '4242'])->save();

        return $m;
    }

    /** @return array<string, InstallmentPlan> */
    private function fixture(Shop $shop): array
    {
        $now = CarbonImmutable::now();
        $born = $now->subDays(300);
        $healthy = $this->card($shop, 12, 2030);

        // HIGH: two failures after the last success.
        $streak = $this->plan($shop, '1', 50, createdAt: $born, attributes: ['customer_name' => 'Dana Levi', 'customer_email' => 'dana@x.test', 'payment_method_id' => $healthy->getKey()]);
        foreach ([150, 120, 90] as $ago) {
            $this->charge($streak, 50, $now->subDays($ago));
        }
        $this->charge($streak, 50, $now->subDays(60), PaymentLedger::STATUS_FAILED);
        $this->charge($streak, 50, $now->subDays(30), PaymentLedger::STATUS_FAILED);

        // HIGH: card already expired.
        $expired = $this->plan($shop, '2', 60, createdAt: $born, attributes: ['customer_name' => '=cmd|calc', 'payment_method_id' => $this->card($shop, 8, 2026)->getKey()]);
        foreach ([150, 120, 90] as $ago) {
            $this->charge($expired, 60, $now->subDays($ago));
        }

        // MEDIUM: card expiring within 60 days (11/2026).
        $expiring = $this->plan($shop, '3', 70, createdAt: $born, attributes: ['customer_name' => 'Maya Shalev', 'payment_method_id' => $this->card($shop, 11, 26)->getKey()]);
        foreach ([150, 120, 90] as $ago) {
            $this->charge($expiring, 70, $now->subDays($ago));
        }

        // LOW: short history (one order).
        $fresh = $this->plan($shop, '4', 40, createdAt: $now->subDays(20), attributes: ['customer_name' => 'Yael Barak', 'payment_method_id' => $healthy->getKey()]);
        $this->charge($fresh, 40, $now->subDays(20));

        // NOT AT RISK: long, clean history, valid card.
        $fine = $this->plan($shop, '5', 30, createdAt: $born, attributes: ['customer_name' => 'Noa Peretz', 'payment_method_id' => $healthy->getKey()]);
        foreach ([150, 120, 90, 60, 30] as $ago) {
            $this->charge($fine, 30, $now->subDays($ago));
        }

        // Out of the live book entirely.
        $this->plan($shop, '6', 30, status: 'cancelled', createdAt: $born);

        return compact('streak', 'expired', 'expiring', 'fresh', 'fine');
    }

    private function risk(): RiskQuery
    {
        return new RiskQuery(Filters::none());
    }

    public function test_levels_follow_the_documented_rules(): void
    {
        $p = $this->fixture($this->shop);

        $rows = Tenant::run($this->shop, fn () => $this->risk()->page()['rows']);
        $byId = array_column($rows, null, 'id');

        $this->assertCount(4, $rows, 'The clean plan and the cancelled plan are not listed.');
        $this->assertSame(RiskQuery::LEVEL_HIGH, $byId[$p['streak']->id]['risk']);
        $this->assertSame(2, $byId[$p['streak']->id]['streak']);
        $this->assertEqualsWithDelta(60.0, $byId[$p['streak']->id]['success'], 0.01);
        $this->assertSame(RiskQuery::LEVEL_HIGH, $byId[$p['expired']->id]['risk']);
        $this->assertSame(RiskQuery::CARD_EXPIRED, $byId[$p['expired']->id]['card']);
        $this->assertSame(RiskQuery::LEVEL_MEDIUM, $byId[$p['expiring']->id]['risk']);
        $this->assertSame(RiskQuery::CARD_EXPIRING, $byId[$p['expiring']->id]['card'], 'A two-digit year is read as 20YY.');
        $this->assertSame('11/2026', $byId[$p['expiring']->id]['card_exp']);
        $this->assertSame(RiskQuery::LEVEL_LOW, $byId[$p['fresh']->id]['risk']);

        $t = Tenant::run($this->shop, fn () => $this->risk()->totals());
        $this->assertSame(4, $t['at_risk']);
        $this->assertSame(2, $t['high']);
        $this->assertSame(1, $t['expiring']);
        $this->assertSame(5, $t['live']);
        $this->assertEqualsWithDelta(220.0, $t['mrr_at_risk'], 0.01);
    }

    public function test_search_filters_sort_and_pagination(): void
    {
        $p = $this->fixture($this->shop);

        Tenant::run($this->shop, function () use ($p): void {
            $this->assertSame(1, $this->risk()->page('dana')['total']);
            $this->assertSame(1, $this->risk()->page('#'.$p['fresh']->id)['total']);
            $this->assertSame(0, $this->risk()->page('100%_')['total'], 'LIKE wildcards in a search are literal.');
            $this->assertSame(2, $this->risk()->page('', 'high')['total']);
            $this->assertSame(1, $this->risk()->page('', '', RiskQuery::CARD_EXPIRING)['total']);

            $bySuccess = array_column($this->risk()->page('', '', '', 'success', 'asc')['rows'], 'id');
            $this->assertSame($p['streak']->id, $bySuccess[0]);

            $paged = $this->risk()->page('', '', '', 'created', 'desc', 2, 3);
            $this->assertSame(4, $paged['total']);
            $this->assertCount(1, $paged['rows']);
        });
    }

    public function test_another_shops_subscriptions_never_appear(): void
    {
        $this->fixture($this->other);
        $this->plan($this->shop, '9', 30, createdAt: CarbonImmutable::now()->subDays(10));

        $t = Tenant::run($this->shop, fn () => $this->risk()->totals());
        $this->assertSame(1, $t['live']);
        $this->assertSame(1, $t['at_risk']);
        $this->assertSame(0, $t['high']);

        $export = Tenant::run($this->shop, fn () => (new CancellationsRisk())->export(Context::default()));
        $this->assertCount(1, $export['rows']);

        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
        $response = Livewire::test(RiskTable::class)->call('export')->effects['download'] ?? null;
        $this->assertNotNull($response);
        $csv = base64_decode($response['content']);
        $this->assertStringNotContainsString('Dana Levi', $csv);
    }

    public function test_the_table_export_is_formula_safe(): void
    {
        $this->fixture($this->shop);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        $download = Livewire::test(RiskTable::class)->call('export')->effects['download'];
        $csv = base64_decode($download['content']);

        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringContainsString('Dana Levi', $csv);
    }

    public function test_the_screen_and_its_live_table_render(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'cancellations', 'risk')
            ->assertOk()
            ->assertSee(__('analytics/cancellations_risk.kpi.at_risk'))
            ->assertSee(__('analytics/cancellations_risk.empty.title'));

        $this->fixture($this->shop);

        Livewire::test(RiskTable::class)
            ->assertSee('Dana Levi')
            ->assertSee(__('analytics/cancellations_risk.level.high'))
            ->set('search', 'maya')
            ->assertSee('Maya Shalev')
            ->assertDontSee('Dana Levi')
            ->set('search', '')
            ->call('sortBy', 'success')
            ->assertSet('sort', 'success')
            ->call('sortBy', 'nonsense')
            ->assertSet('sort', 'success')
            ->set('level', 'bogus')
            ->assertOk();
    }
}
