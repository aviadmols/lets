<?php

namespace Tests\Feature\Import;

use App\Domain\Import\ImportOptions;
use App\Domain\Import\SubscriptionImporter;
use App\Domain\Import\SubscriptionReleaser;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trying one member, and the promise that nobody is charged until the merchant
 * says so.
 *
 * The hold is the only thing standing between a migrated store and a few thousand
 * cards being billed the moment someone flips a switch too early, so the cases
 * here are the ones where that could go wrong: a held plan must be invisible to
 * the scheduler, a hand-paused plan must be invisible to the release, and a
 * period that expired during the migration must NOT become an immediate charge.
 */
final class HoldAndReleaseTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'hold.example.com',
            'name' => 'Hold',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        Tenant::clear();
        parent::tearDown();
    }

    // === Trying one member ===

    public function test_only_imports_the_named_membership_and_skips_the_rest(): void
    {
        $path = $this->file([
            ['1001', 'monthly', '50.00', 'active', '2026-09-01 00:00:00'],
            ['1002', 'monthly', '60.00', 'active', '2026-09-01 00:00:00'],
            ['1003', 'monthly', '70.00', 'active', '2026-09-01 00:00:00'],
        ]);

        $report = (new SubscriptionImporter)->import(
            $this->shop,
            $path,
            new ImportOptions(only: ['1002']),
        );

        $this->assertFalse($report->aborted, $report->abortReason ?? '');
        $this->assertSame(1, $report->written);
        $this->assertSame(2, $report->skipped);
        $this->assertSame(['1002'], InstallmentPlan::query()->pluck('import_key')->all());
    }

    public function test_a_broken_row_elsewhere_does_not_block_trying_one_member(): void
    {
        $path = $this->file([
            ['1001', 'monthly', '50.00', 'active', '2026-09-01 00:00:00'],
            ['1002', 'never-ever', 'oops', 'who-knows', 'not-a-date'],
        ]);

        // The whole-file rule is about the rows this run is importing. A member you
        // are testing should not be held hostage by a row you are still fixing.
        $report = (new SubscriptionImporter)->import(
            $this->shop,
            $path,
            new ImportOptions(only: ['1001']),
        );

        $this->assertFalse($report->aborted, $report->abortReason ?? '');
        $this->assertSame(1, $report->written);
    }

    public function test_limit_stops_after_the_first_rows(): void
    {
        $path = $this->file([
            ['1001', 'monthly', '50.00', 'active', '2026-09-01 00:00:00'],
            ['1002', 'monthly', '60.00', 'active', '2026-09-01 00:00:00'],
            ['1003', 'monthly', '70.00', 'active', '2026-09-01 00:00:00'],
        ]);

        (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions(limit: 2));

        $this->assertSame(2, InstallmentPlan::query()->count());
    }

    public function test_a_filter_that_matches_nothing_says_so(): void
    {
        $path = $this->file([['1001', 'monthly', '50.00', 'active', '2026-09-01 00:00:00']]);

        $report = (new SubscriptionImporter)->dryRun($this->shop, $path, new ImportOptions(only: ['9999']));

        $this->assertTrue($report->aborted);
        $this->assertSame(__('import.abort.no_match'), $report->abortReason);
    }

    // === The hold ===

    public function test_a_held_import_lands_paused_and_unscheduled(): void
    {
        $path = $this->file([['1001', 'monthly', '50.00', 'active', '2026-09-01 00:00:00']]);

        // Even asked to start charging: the hold beats it, because a panic button
        // with exceptions is not a panic button.
        $report = (new SubscriptionImporter)->import(
            $this->shop,
            $path,
            new ImportOptions(startCharging: true, holdAsPaused: true),
        );

        $this->assertSame(1, $report->held);

        $plan = InstallmentPlan::query()->firstOrFail();
        $this->assertSame(PlanStatus::PAUSED, $plan->status);
        $this->assertNull($plan->next_charge_at);
        $this->assertTrue($plan->meta['import']['held']);

        // And the scheduler's own filter agrees this plan does not exist for it.
        $this->assertSame(0, InstallmentPlan::query()
            ->whereIn('status', [PlanStatus::ACTIVE->value, PlanStatus::AWAITING_FIRST_PAYMENT->value])
            ->whereNotNull('next_charge_at')
            ->count());
    }

    public function test_a_hold_does_not_resurrect_a_cancelled_member(): void
    {
        $path = $this->file([['1001', 'monthly', '50.00', 'cancelled', '']]);

        (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions(holdAsPaused: true));

        $plan = InstallmentPlan::query()->firstOrFail();
        $this->assertSame(PlanStatus::CANCELLED, $plan->status);
        $this->assertArrayNotHasKey('held', $plan->meta['import']);
    }

    // === The release ===

    public function test_releasing_restores_active_and_sets_a_future_date(): void
    {
        $path = $this->file([['1001', 'monthly', '50.00', 'active', '2026-09-01 00:00:00']]);
        (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions(holdAsPaused: true));

        $releaser = new SubscriptionReleaser;
        $this->assertSame(1, $releaser->heldCount($this->shop));

        // A preview writes nothing.
        $preview = $releaser->release($this->shop);
        $this->assertSame(1, $preview->found);
        $this->assertSame(0, $preview->released);
        $this->assertSame(PlanStatus::PAUSED, InstallmentPlan::query()->firstOrFail()->status);

        $report = $releaser->release($this->shop, write: true);
        $this->assertSame(1, $report->released);

        $plan = InstallmentPlan::query()->firstOrFail();
        $this->assertSame(PlanStatus::ACTIVE, $plan->status);
        $this->assertNotNull($plan->next_charge_at);
        $this->assertTrue($plan->next_charge_at->isFuture());
        $this->assertArrayNotHasKey('held', $plan->meta['import']);

        // Nothing is left on hold, so a second release is a no-op.
        $this->assertSame(0, $releaser->heldCount($this->shop));
    }

    public function test_a_period_that_expired_during_the_migration_is_rolled_forward_not_charged_at_once(): void
    {
        // The store's period ended eight months ago; go-live is today.
        $stale = CarbonImmutable::now()->subMonths(8)->format('Y-m-d H:i:s');
        $path = $this->file([['1001', 'monthly', '50.00', 'active', $stale]]);

        (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions(holdAsPaused: true));

        $report = (new SubscriptionReleaser)->release($this->shop, write: true);

        $plan = InstallmentPlan::query()->firstOrFail();
        $this->assertSame(1, $report->rolled);
        $this->assertTrue($plan->next_charge_at->isFuture());
        // One cycle ahead, not eight cycles of back-billing.
        $this->assertTrue($plan->next_charge_at->lte(CarbonImmutable::now()->addMonth()->addDay()));
    }

    public function test_a_plan_paused_by_hand_is_invisible_to_the_release(): void
    {
        $path = $this->file([['1001', 'monthly', '50.00', 'active', '2026-09-01 00:00:00']]);
        (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions);

        // The merchant pauses this one themselves, because the customer asked.
        InstallmentPlan::query()->firstOrFail()->transitionTo(PlanStatus::PAUSED);

        $releaser = new SubscriptionReleaser;
        $this->assertSame(0, $releaser->heldCount($this->shop));

        $releaser->release($this->shop, write: true);

        $this->assertSame(PlanStatus::PAUSED, InstallmentPlan::query()->firstOrFail()->status);
    }

    public function test_release_can_be_limited_to_one_membership(): void
    {
        $path = $this->file([
            ['1001', 'monthly', '50.00', 'active', '2026-09-01 00:00:00'],
            ['1002', 'monthly', '60.00', 'active', '2026-09-01 00:00:00'],
        ]);
        (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions(holdAsPaused: true));

        (new SubscriptionReleaser)->release($this->shop, only: ['1001'], write: true);

        $this->assertSame(PlanStatus::ACTIVE, InstallmentPlan::query()->where('import_key', '1001')->firstOrFail()->status);
        $this->assertSame(PlanStatus::PAUSED, InstallmentPlan::query()->where('import_key', '1002')->firstOrFail()->status);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function file(array $rows): string
    {
        $out = "membership_id,product_id,cycle,plan_amount,status,current_period_end,card_token\n";

        foreach ($rows as [$id, $cycle, $amount, $status, $periodEnd]) {
            $out .= implode(',', [$id, '7788', $cycle, $amount, $status, $periodEnd, 'tok'])."\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'lets-hold-').'.csv';
        file_put_contents($path, $out);
        $this->files[] = $path;

        return $path;
    }
}
