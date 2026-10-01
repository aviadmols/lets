<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Reports\ReportCatalog;
use App\Domain\Analytics\Reports\ReportRunner;
use App\Filament\Pages\Analytics;
use App\Livewire\Analytics\ReportLibrary;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Analytics\Concerns\BuildsLedger;
use Tests\Feature\Analytics\Concerns\BuildsSubscriptions;
use Tests\Feature\Analytics\Concerns\FollowsAnalyticsDownloads;
use Tests\TestCase;

/**
 * Reports: every report the library marks available runs as a CSV for the
 * bound shop only, with formula-safe cells; untracked reports are listed
 * disabled and cannot be run; the Exports tab is an honest empty state.
 */
final class ReportsTest extends TestCase
{
    use BuildsLedger;
    use BuildsSubscriptions;
    use FollowsAnalyticsDownloads;
    use RefreshDatabase;

    // === CONSTANTS ===
    private const MINE = 'Mine Customer';

    private const THEIRS = 'Their Customer';

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

    private function fixture(Shop $shop, string $name): void
    {
        $now = CarbonImmutable::now();
        $plan = $this->plan($shop, $name === self::MINE ? '1' : '2', 50, createdAt: $now->subDays(10), attributes: [
            'customer_name' => $name, 'customer_email' => strtolower(str_replace(' ', '.', $name)).'@x.test',
            'next_charge_at' => $now->addDays(5), 'external_product_id' => 'p-'.$name,
        ]);
        $this->charge($plan, 50, $now->subDays(3))->forceFill(['customer_name' => $name])->save();

        $gone = $this->plan($shop, $name === self::MINE ? '3' : '4', 30, createdAt: $now->subDays(40), attributes: ['customer_name' => $name]);
        $gone->forceFill(['status' => 'cancelled'])->saveQuietly();
        $event = new ActivityEvent();
        $event->forceFill([
            'shop_id' => $shop->getKey(), 'plan_id' => $gone->getKey(), 'payment_id' => null, 'actor' => 'admin:1', 'kind' => 'status_changed',
            'details' => ['model' => 'InstallmentPlan', 'from' => 'active', 'to' => 'cancelled', 'reason' => '=SUM(A1)', 'invoice_url' => 'https://secret.example/doc'],
            'created_at' => $now->subDays(2),
        ])->save();
    }

    private function csv(string $report): string
    {
        return Tenant::run($this->shop, function () use ($report): string {
            $out = fopen('php://memory', 'w+');
            (new ReportRunner(Period::default(), Filters::none()))->write($report, $out);
            rewind($out);

            return (string) stream_get_contents($out);
        });
    }

    /** @return list<string> */
    private function available(): array
    {
        $out = [];
        foreach (ReportCatalog::REPORTS as $reports) {
            foreach ($reports as $key => $method) {
                if ($method !== null && $key !== ReportRunner::SUBSCRIPTIONS) {
                    $out[] = $key;
                }
            }
        }

        return $out;
    }

    public function test_every_available_report_reads_only_the_bound_shop(): void
    {
        $this->fixture($this->shop, self::MINE);
        $this->fixture($this->other, self::THEIRS);

        foreach ($this->available() as $report) {
            $csv = $this->csv($report);
            $this->assertStringStartsWith(ReportRunner::BOM, $csv, "$report has no BOM.");
            $this->assertStringNotContainsString(self::THEIRS, $csv, "$report leaks another shop's rows.");
            $this->assertStringNotContainsString('their.customer', $csv, "$report leaks another shop's rows.");
            $this->assertStringNotContainsString('p-'.self::THEIRS, $csv, "$report leaks another shop's products.");
        }

        $this->assertStringContainsString(self::MINE, $this->csv('transaction_logs'));
        $this->assertStringContainsString(self::MINE, $this->csv('scheduled_upcoming_orders'));
        $this->assertStringContainsString(self::MINE, $this->csv('cancellation_logs'));
        $this->assertStringContainsString(self::MINE, $this->csv('subscribers'));
    }

    public function test_cells_are_formula_safe_and_timeline_secrets_never_leave(): void
    {
        $this->fixture($this->shop, self::MINE);

        $log = $this->csv('subscription_activity_logs');
        $this->assertStringContainsString("'=SUM(A1)", $log);
        $this->assertStringNotContainsString('secret.example', $log);
        $this->assertStringContainsString("'=SUM(A1)", $this->csv('cancellation_logs'));
    }

    public function test_untracked_reports_cannot_run(): void
    {
        $this->assertFalse(ReportCatalog::isAvailable('bundle_orders'));
        $this->assertNull(Tenant::run($this->shop, fn () => (new ReportRunner(Period::default(), Filters::none()))->download('bundle_orders')));
        $this->assertNull(Tenant::run($this->shop, fn () => (new ReportRunner(Period::default(), Filters::none()))->download('../../etc')));
    }

    public function test_the_library_lists_reports_and_runs_them_as_downloads(): void
    {
        $this->fixture($this->shop, self::MINE);
        $this->fixture($this->other, self::THEIRS);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'reports', 'reports')
            ->assertOk()
            ->assertSee(__('analytics/reports_reports.report.transaction_logs.title'))
            ->assertSee(__('analytics/reports_reports.report.bundle_orders.title'))
            ->assertSee(__('analytics.empty.not_tracked_title'));

        $logsUrl = $this->downloadLink(Livewire::test(ReportLibrary::class, ['from' => '2026-08-31', 'to' => '2026-09-29'])
            ->set('search', 'cancellation')
            ->assertSee(__('analytics/reports_reports.report.cancellation_logs.title'))
            ->assertDontSee(__('analytics/reports_reports.report.transaction_logs.title'))
            ->call('run', 'cancellation_logs'));
        $subscriptionsUrl = $this->downloadLink(Livewire::test(ReportLibrary::class, ['from' => '2026-08-31', 'to' => '2026-09-29'])
            ->call('run', 'subscriptions'));
        $untracked = Livewire::test(ReportLibrary::class, ['from' => '2026-08-31', 'to' => '2026-09-29'])
            ->call('run', 'bundle_orders');
        $this->assertArrayNotHasKey('redirect', $untracked->effects);

        $this->assertStringNotContainsString(self::THEIRS, $this->fetchCsv($logsUrl));
        $content = $this->fetchCsv($subscriptionsUrl);
        $this->assertStringContainsString('mine.customer@x.test', $content);
        $this->assertStringNotContainsString('their.customer@x.test', $content);
    }

    public function test_the_exports_tab_is_an_honest_empty_state(): void
    {
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(Analytics::class)->call('go', 'reports', 'exports')
            ->assertOk()
            ->assertSee(__('analytics/reports_exports.empty.title'))
            ->assertSee('rc-an-empty--not-tracked', false);
    }
}
