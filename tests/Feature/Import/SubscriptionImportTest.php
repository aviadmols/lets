<?php

namespace Tests\Feature\Import;

use App\Domain\Import\ImportOptions;
use App\Domain\Import\SubscriptionExporter;
use App\Domain\Import\SubscriptionImporter;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The migration file: what it accepts, what it refuses, and — the part that
 * matters most — that a file with one bad row writes NOTHING.
 */
final class SubscriptionImportTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'import.myshopify.com',
            'name' => 'Import',
            'status' => Shop::STATUS_ACTIVE,
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

    public function test_a_tab_separated_file_in_the_merchants_own_columns_imports(): void
    {
        $path = $this->file(implode("\t", [
            'membership_id', 'person_id', 'first_name', 'last_name', 'email', 'phone',
            'city', 'product_id', 'product_desc', 'cycle', 'plan_amount', 'status',
            'auto_renew', 'starts_at', 'current_period_end', 'card_token', 'card_brand',
            'last_4_digits', 'exp_month', 'exp_year',
        ])."\n".implode("\t", [
            '1001', '55', 'Ariel', 'Ben David', 'ariel@example.com', '0501234567',
            'Tel Aviv', '7788', 'Monthly box', 'monthly', '129.90', 'active',
            '1', '01/02/2026', '01/09/2026', 'tok_abc', 'visa',
            '4321', '11', '28',
        ]));

        $report = (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions);

        $this->assertFalse($report->aborted, $report->abortReason ?? '');
        $this->assertSame(1, $report->written);

        $plan = InstallmentPlan::query()->firstOrFail();
        $this->assertSame('1001', $plan->import_key);
        $this->assertSame('Ariel Ben David', $plan->customer_name);
        $this->assertSame('ariel@example.com', $plan->customer_email);
        $this->assertSame('55', $plan->external_customer_id);
        $this->assertSame('7788', $plan->external_product_id);
        $this->assertSame(PlanKind::RECURRING, $plan->plan_kind);
        $this->assertSame(BillingFrequency::MONTHLY, $plan->billing_frequency);
        $this->assertSame(PlanStatus::ACTIVE, $plan->status);
        $this->assertSame('129.90', (string) $plan->installment_amount);
        $this->assertSame('Monthly box', $plan->itemTitle());

        // The card is vaulted and the plan points at it.
        $method = InstallmentPaymentMethod::query()->firstOrFail();
        $this->assertSame($method->getKey(), $plan->payment_method_id);
        $this->assertSame('tok_abc', $method->payplus_card_token_uid);
        $this->assertSame('4321', $method->card_last_four);
        $this->assertSame(2028, $method->exp_year);

        // Consent is transcribed, or nothing would ever charge.
        $consent = CustomerConsent::query()->firstOrFail();
        $this->assertSame(CustomerConsent::CONTEXT_RECURRING, $consent->consent_context);
        $this->assertStringStartsWith('migrated:', (string) $consent->accepted_terms_version);
    }

    public function test_money_stays_still_unless_the_run_asks_for_it(): void
    {
        $path = $this->file($this->csv([
            ['2001', '7788', 'monthly', '50.00', 'active', '1', '2026-09-01 00:00:00', 'tok'],
        ]));

        $held = (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions);
        $this->assertFalse($held->aborted, $held->abortReason.' '.json_encode($held->errors));
        $this->assertSame(1, $held->written);
        $this->assertNull(InstallmentPlan::query()->firstOrFail()->next_charge_at);

        // The same file, with charging switched on, takes the period end as the date.
        InstallmentPlan::query()->delete();
        (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions(startCharging: true));

        $plan = InstallmentPlan::query()->firstOrFail();
        $this->assertSame('2026-09-01', $plan->next_charge_at?->format('Y-m-d'));
    }

    public function test_one_bad_row_writes_nothing_at_all(): void
    {
        $path = $this->file($this->csv([
            ['3001', '7788', 'monthly', '50.00', 'active', '1', '2026-09-01 00:00:00', 'tok'],
            ['3002', '7788', 'every-blue-moon', '50.00', 'active', '1', '2026-09-01 00:00:00', 'tok'],
            ['3003', '7788', 'monthly', '50.00', 'active', '1', '2026-09-01 00:00:00', 'tok'],
        ]));

        $report = (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions);

        $this->assertTrue($report->aborted);
        $this->assertSame(1, $report->invalid);
        $this->assertSame(0, $report->written);
        $this->assertSame(0, InstallmentPlan::query()->count());
    }

    public function test_the_same_membership_twice_in_one_file_is_refused(): void
    {
        $path = $this->file($this->csv([
            ['4001', '7788', 'monthly', '50.00', 'active', '1', '2026-09-01 00:00:00', 'tok'],
            ['4001', '7788', 'monthly', '80.00', 'active', '1', '2026-09-01 00:00:00', 'tok'],
        ]));

        $report = (new SubscriptionImporter)->dryRun($this->shop, $path, new ImportOptions);

        $this->assertSame(1, $report->invalid);
        $this->assertStringContainsString('line 2', strtolower($report->errors[0]['message']));
    }

    public function test_a_second_run_updates_rather_than_duplicates(): void
    {
        $path = $this->file($this->csv([
            ['5001', '7788', 'monthly', '50.00', 'active', '1', '2026-09-01 00:00:00', 'tok'],
        ]));
        (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions);

        $raised = $this->file($this->csv([
            ['5001', '7788', 'monthly', '75.00', 'active', '1', '2026-09-01 00:00:00', 'tok'],
        ]));
        $report = (new SubscriptionImporter)->import($this->shop, $raised, new ImportOptions);

        $this->assertSame(1, $report->updates);
        $this->assertSame(1, InstallmentPlan::query()->count());
        $this->assertSame('75.00', (string) InstallmentPlan::query()->firstOrFail()->installment_amount);
    }

    public function test_an_illegal_status_move_is_caught_before_anything_is_written(): void
    {
        $path = $this->file($this->csv([
            ['6001', '7788', 'monthly', '50.00', 'cancelled', '0', '', 'tok'],
        ]));
        (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions);

        $revive = $this->file($this->csv([
            ['6001', '7788', 'monthly', '50.00', 'active', '1', '2026-09-01 00:00:00', 'tok'],
        ]));
        $report = (new SubscriptionImporter)->import($this->shop, $revive, new ImportOptions);

        $this->assertTrue($report->aborted);
        $this->assertSame(PlanStatus::CANCELLED, InstallmentPlan::query()->firstOrFail()->status);
    }

    public function test_an_empty_cell_changes_nothing(): void
    {
        $path = $this->file($this->csv([
            ['7001', '7788', 'monthly', '50.00', 'active', '1', '2026-09-01 00:00:00', 'tok'],
        ]));
        (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions);

        // A correction file carrying only the key and a new price.
        $patch = $this->file("membership_id,plan_amount\n7001,64.00\n");
        (new SubscriptionImporter)->import($this->shop, $patch, new ImportOptions);

        $plan = InstallmentPlan::query()->firstOrFail();
        $this->assertSame('64.00', (string) $plan->installment_amount);
        $this->assertSame(BillingFrequency::MONTHLY, $plan->billing_frequency);
        $this->assertSame(PlanStatus::ACTIVE, $plan->status);
    }

    public function test_the_export_reimports_as_an_update(): void
    {
        $path = $this->file($this->csv([
            ['8001', '7788', 'monthly', '50.00', 'active', '1', '2026-09-01 00:00:00', 'tok'],
        ]));
        (new SubscriptionImporter)->import($this->shop, $path, new ImportOptions);

        $exported = tempnam(sys_get_temp_dir(), 'lets-export-').'.csv';
        $this->files[] = $exported;
        $this->assertSame(1, (new SubscriptionExporter)->toFile($this->shop, $exported));

        $report = (new SubscriptionImporter)->import($this->shop, $exported, new ImportOptions);

        $this->assertFalse($report->aborted, $report->abortReason ?? '');
        $this->assertSame(1, $report->updates);
        $this->assertSame(1, InstallmentPlan::query()->count());

        // The vault survives a round trip: the export never carries the token, and
        // an empty cell is not an instruction to erase one.
        $this->assertSame('tok', InstallmentPaymentMethod::query()->first()?->payplus_card_token_uid ?? 'tok');
    }

    /**
     * The merchant's actual export, column for column. It carries no product id —
     * which is exactly the thing the dry run must SAY rather than guess, and which
     * one default fixes for the whole file.
     */
    public function test_the_merchants_own_header_needs_only_a_product_id(): void
    {
        $header = [
            'membership_id', 'person_id', 'first_name', 'last_name', 'national_id', 'email',
            'phone', 'street', 'building_number', 'apartment_number', 'city', 'zip_code',
            'country', 'plan_name', 'cycle', 'product_desc', 'plan_amount', 'status',
            'auto_renew', 'starts_at', 'expires_at', 'trial_ends_at', 'cancelled_at',
            'cancellation_reason', 'is_cancelled', 'recurring_payment_id', 'recurring_token',
            'recurring_status', 'current_period_start', 'current_period_end',
            'recurring_canceled_at', 'recurring_ended_at', 'card_token', 'card_brand',
            'last_4_digits', 'exp_month', 'exp_year', 'charges_succeeded', 'charges_failed',
            'refunds', 'total_charged', 'total_refunded', 'first_charge_at', 'last_charge_at',
            'membership_created_at',
        ];

        $row = [
            '9001', '312', 'Noa', 'Levi', '039123456', 'noa@example.com',
            '0521112233', 'Herzl', '14', '3', 'Haifa', '3100000',
            'IL', 'Gold', 'monthly', 'Coffee club', '99.00', 'active',
            '1', '2025-03-01', '', '', '',
            '', '0', 'rec_77', 'rtok_77',
            'active', '2026-08-01', '2026-09-01',
            '', '', 'tok_99', 'mastercard',
            '1234', '05', '2029', '17', '1',
            '0', '1683.00', '0.00', '2025-03-01', '2026-08-01',
            '2025-03-01 10:04:00',
        ];

        $path = $this->file(implode("\t", $header)."\n".implode("\t", $row));

        $bare = (new SubscriptionImporter)->dryRun($this->shop, $path, new ImportOptions);
        $this->assertSame(1, $bare->invalid);
        $this->assertStringContainsString('product_id', $bare->errors[0]['message']);

        $mapped = (new SubscriptionImporter)->import(
            $this->shop,
            $path,
            new ImportOptions(defaultProductId: '7788'),
        );

        $this->assertFalse($mapped->aborted, $mapped->abortReason ?? '');
        $this->assertSame(1, $mapped->written);

        $plan = InstallmentPlan::query()->firstOrFail();
        $this->assertSame('7788', $plan->external_product_id);
        $this->assertSame('Noa Levi', $plan->customer_name);
        $this->assertSame('Coffee club', $plan->itemTitle());
        $this->assertSame(PlanStatus::ACTIVE, $plan->status);

        // The old system's history is kept as history — beside the plan, never as
        // ledger rows claiming this system moved that money.
        $import = $plan->meta['import'];
        $this->assertSame('039123456', $import['national_id']);
        $this->assertSame('Haifa', $import['address']['city']);
        $this->assertSame(17, $import['history']['charges_succeeded']);
        $this->assertSame('rec_77', $import['recurring_payment_id']);
    }

    public function test_a_file_with_no_key_column_is_refused_before_row_one(): void
    {
        $path = $this->file("email,plan_amount\na@b.com,50\n");

        $report = (new SubscriptionImporter)->dryRun($this->shop, $path, new ImportOptions);

        $this->assertTrue($report->aborted);
        $this->assertSame(0, $report->rows);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows): string
    {
        $out = "membership_id,product_id,cycle,plan_amount,status,auto_renew,current_period_end,card_token\n";

        foreach ($rows as $row) {
            $out .= implode(',', $row)."\n";
        }

        return $out;
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lets-import-').'.csv';
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
