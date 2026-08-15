<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Jobs\ImportSubscriptionsJob;
use App\Filament\Pages\ImportSubscriptions;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screen a merchant migrates a store from.
 *
 * The gate is the whole design: a file is CHECKED here and WRITTEN by a worker,
 * and the write cannot be reached until the check came back clean. So that is what
 * these cover — a bad file reports and stays a report, a good one queues.
 */
final class ImportSubscriptionsPageTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'import-page.example.com',
            'name' => 'Import Page',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_page_renders(): void
    {
        Livewire::test(ImportSubscriptions::class)
            ->assertOk()
            ->assertSee(__('import.report.empty'));
    }

    public function test_a_broken_file_reports_and_stays_a_report(): void
    {
        Queue::fake();

        $component = Livewire::test(ImportSubscriptions::class)
            ->set('upload', $this->csv("membership_id,product_id,cycle,plan_amount,status\n1,7788,fortnight-ish,50,active\n"))
            ->call('check');

        $this->assertSame(1, $component->get('report')['invalid']);
        $this->assertFalse($component->instance()->reportIsClean());

        // Pressing import anyway does nothing — the gate is in the method, not
        // only in the button that is hidden from the merchant.
        $component->call('commit');
        Queue::assertNothingPushed();
        $this->assertSame(0, InstallmentPlan::query()->count());
    }

    public function test_a_clean_file_queues_the_write(): void
    {
        Queue::fake();

        $component = Livewire::test(ImportSubscriptions::class)
            ->set('upload', $this->csv("membership_id,product_id,cycle,plan_amount,status,card_token\n1,7788,monthly,50,active,tok\n"))
            ->call('check');

        $this->assertTrue($component->instance()->reportIsClean());
        $this->assertSame(1, $component->get('report')['creates']);

        // Checking alone writes nothing.
        $this->assertSame(0, InstallmentPlan::query()->count());

        $component->call('commit');

        Queue::assertPushed(ImportSubscriptionsJob::class, fn (ImportSubscriptionsJob $job): bool => $job->shopId === (int) $this->shop->getKey());
    }

    /**
     * The reported bug: check the file, then press Import, and nothing happens.
     *
     * The commit used to read Livewire's temporary upload a second time. That file
     * lives on the disk of whichever web container took the upload, so on a
     * multi-replica deploy the next click could land somewhere it does not exist —
     * and the guard returned void, so the button looked broken rather than blocked.
     * The file is stashed in the shared cache at CHECK time now, so losing the
     * temporary upload changes nothing.
     */
    public function test_the_import_still_runs_after_the_temporary_upload_is_gone(): void
    {
        Queue::fake();

        $component = Livewire::test(ImportSubscriptions::class)
            ->set('upload', $this->csv("membership_id,product_id,cycle,plan_amount,status,card_token\n1,7788,monthly,50,active,tok\n"))
            ->call('check');

        $this->assertTrue($component->instance()->reportIsClean());

        // The temporary upload disappears between the two clicks.
        $component->set('upload', null)->call('commit');

        Queue::assertPushed(ImportSubscriptionsJob::class);
    }

    /** A refusal is always spoken. "Nothing happened" is not an acceptable answer. */
    public function test_committing_without_a_checked_file_says_why(): void
    {
        Queue::fake();

        Livewire::test(ImportSubscriptions::class)
            ->call('commit')
            ->assertNotified(__('import.error.not_checked'));

        Queue::assertNothingPushed();
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('members.csv', $contents);
    }
}
