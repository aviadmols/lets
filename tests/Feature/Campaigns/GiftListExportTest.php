<?php

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\GiftExportRunner;
use App\Domain\Campaigns\GiftListExporter;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftExportRun;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The list as a courier's sheet, so a merchant can ship the gifts by hand.
 *
 * It answers one question per row: where would this person's package go? Which
 * means a recipient with nowhere to send it is still a row, carrying the reason —
 * a file that quietly omits them looks complete and is not.
 *
 * Built on a worker: every line is a live store read, and a real list is minutes
 * of work that used to be squeezed into one click — and came back cut short.
 */
final class GiftListExportTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'gift-export.example.com',
            'name' => 'Gift Export',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $this->shop->woocommerce_credentials = [
            'base_url' => 'https://gift-export.example.com',
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ];
        $this->shop->save();
        $this->shop = $this->shop->fresh();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_each_qualifier_is_a_row_in_the_courier_sheet_shape(): void
    {
        Http::fake([
            // Read in bulk: one request for a hundred profiles, not one per person.
            '*/wp-json/wc/v3/customers?*include=55*' => Http::response([[
                'id' => 55,
                'shipping' => [
                    'first_name' => 'דנה', 'last_name' => 'קונה',
                    // The LETS address fields: the street alone, the parts as meta.
                    'address_1' => 'הרצל', 'city' => 'תל אביב',
                    'postcode' => '6100000', 'country' => 'IL',
                ],
                'billing' => ['phone' => '0501234567'],
                'meta_data' => [
                    ['id' => 1, 'key' => 'shipping_building_number', 'value' => '12'],
                    ['id' => 2, 'key' => 'shipping_apartment_number', 'value' => '4'],
                    ['id' => 3, 'key' => 'shipping_floor', 'value' => '2'],
                    ['id' => 4, 'key' => 'shipping_entrance', 'value' => 'ב'],
                ],
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        $this->subscriber('דנה קונה', succeeded: 3, customerId: '55');

        $rows = $this->rows($this->export(1));

        $this->assertSame([
            __('gifts.export.col.first_name'), __('gifts.export.col.last_name'), __('gifts.export.col.phone'),
            __('gifts.export.col.city'), __('gifts.export.col.street'), __('gifts.export.col.building'),
            __('gifts.export.col.entrance'), __('gifts.export.col.apartment'), __('gifts.export.col.floor'),
            __('gifts.export.col.note'),
        ], $rows[0]);

        // First and last name in their own columns. The shipping block has no phone of its
        // own — the billing one is used, because a courier cannot deliver to a number that is
        // not there.
        $this->assertSame(['דנה', 'קונה', '0501234567', 'תל אביב', 'הרצל', '12', 'ב', '4', '2', ''], $rows[1]);
    }

    public function test_an_order_address_is_split_back_into_its_parts(): void
    {
        Http::fake([
            '*/wp-json/wc/v3/orders?*include=900*' => Http::response([[
                'id' => 900,
                // What the plugin folds into an order: "street building" and the
                // labelled parts — plus what the customer asked for at checkout.
                'shipping' => [
                    'first_name' => 'Dana', 'last_name' => 'K',
                    'address_1' => 'הרצל 12א', 'address_2' => 'דירה 4, קומה 2, כניסה ב, ליד המכולת',
                    'city' => 'חיפה', 'phone' => '0520000000',
                ],
                'customer_note' => 'להשאיר אצל השכנים',
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        $this->subscriber('Dana', succeeded: 3, customerId: '0', orderId: '900');

        $rows = $this->rows($this->export(1));

        // Anything address_2 says that is not one of the parts reaches the note,
        // where the courier will still read it.
        $this->assertSame(
            ['Dana', 'K', '0520000000', 'חיפה', 'הרצל', '12א', 'ב', '4', '2', 'ליד המכולת · להשאיר אצל השכנים'],
            $rows[1],
        );
    }

    public function test_a_recipient_with_nowhere_to_ship_is_still_listed(): void
    {
        Http::fake([
            // A profile with a country and nothing to deliver to.
            '*/wp-json/wc/v3/customers?*include=55*' => Http::response([['id' => 55, 'shipping' => ['country' => 'IL']]], 200),
            '*' => Http::response([], 200),
        ]);

        $this->subscriber('Dana', succeeded: 3, customerId: '55');

        $csv = $this->export(1);

        $this->assertStringContainsString('Dana', $csv);
        // The people who need attention are exactly the ones a filtered file hides.
        $this->assertStringContainsString(__('gifts.reason.no_address'), $csv);
    }

    public function test_the_file_opens_correctly_in_excel(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->subscriber('דנה', succeeded: 3, customerId: '0');

        $csv = $this->export(1);

        // Without the BOM, Excel on a Hebrew Windows reads UTF-8 as the ANSI
        // codepage and every Hebrew name arrives as mojibake.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString(__('gifts.export.col.street'), $csv);
    }

    public function test_exporting_creates_nothing(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->subscriber('Dana', succeeded: 3, customerId: '0');

        $this->export(1);

        // Reading a list is not a campaign: no enrolment, no order, no gift.
        Tenant::run($this->shop, function (): void {
            $this->assertSame(0, GiftRecipient::query()->count());
            $this->assertSame(0, GiftCampaign::query()->count());
        });

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/wp-json/wc/v3/orders'));
    }

    /**
     * THE BUG THIS REPLACED: the export ran inside the click, capped at 500 rows
     * and 20 seconds, so a real list came back cut short — or not at all.
     */
    public function test_a_long_list_is_exported_whole_across_many_slices(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $count = 620;
        $this->manySubscribers($count);
        Queue::fake(); // walked by hand below, slice by slice, as a worker would

        $run = Tenant::run($this->shop, fn (): GiftExportRun => app(GiftExportRunner::class)->start($this->shop, 1, [], []));

        $slices = 0;
        Tenant::run($this->shop, function () use ($run, &$slices): void {
            do {
                $slices++;
                $finished = app(GiftExportRunner::class)->advance($this->shop, (int) $run->getKey(), maxRows: 200);
            } while (! $finished && $slices < 20);
        });

        $this->assertGreaterThan(1, $slices, 'the list must not be walked in one go');

        $run = Tenant::run($this->shop, fn (): GiftExportRun => $run->fresh());
        $this->assertSame(GiftExportRun::STATUS_COMPLETED, $run->status);
        $this->assertSame($count, (int) $run->total);
        $this->assertSame($count, (int) $run->processed);

        $csv = Tenant::run($this->shop, fn (): string => app(GiftListExporter::class)->file($run));
        // Header + every recipient, none dropped.
        $this->assertCount($count + 1, $this->rows($csv));
    }

    /**
     * The export's speed: a store read per PERSON made a list of hundreds take many minutes.
     * Now a hundred profiles come back in one request, and an imported member with no store
     * id costs one email lookup — sent several at once — instead of a lookup plus a profile read.
     */
    public function test_a_list_is_read_from_the_store_in_bulk_not_one_request_per_person(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        for ($i = 1; $i <= 150; $i++) {
            $this->subscriber('Member '.$i, succeeded: 3, customerId: (string) (1000 + $i), email: 'member'.$i.'@example.com');
        }
        $this->subscriber('Imported Member', succeeded: 3, customerId: '0', email: 'imported@example.com');

        $csv = $this->export(1);

        $this->assertCount(152, $this->rows($csv));
        $profileReads = collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/customers') && ! str_contains($pair[0]->url(), 'email='));
        $this->assertCount(2, $profileReads, '150 profiles in two bulk reads (100 + 50)');
        $this->assertCount(1, collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'email=')));
        // An imported member whose store holds only one name field still gets two columns.
        $this->assertContains(['Imported', 'Member', '', '', '', '', '', '', '', __('gifts.reason.no_address')], $this->rows($csv));
    }

    /** A re-delivered slice must not write — or count — a row twice. */
    public function test_a_row_is_counted_once_whichever_attempt_writes_it(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->subscriber('Dana', succeeded: 3, customerId: '0');
        Queue::fake();

        $run = Tenant::run($this->shop, function (): GiftExportRun {
            $runner = app(GiftExportRunner::class);
            $run = $runner->start($this->shop, 1, [], []);
            $runner->advance($this->shop, (int) $run->getKey());
            $runner->advance($this->shop, (int) $run->getKey());

            return $run->fresh();
        });

        $this->assertSame(GiftExportRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, (int) $run->processed);
    }

    /** The lines are customers' addresses: encrypted, and gone with the next export. */
    public function test_export_lines_are_encrypted_and_replaced_by_the_next_export(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->subscriber('Dana Secret', succeeded: 3, customerId: '0');

        $this->export(1);
        $this->assertStringNotContainsString('Dana Secret', (string) DB::table('gift_export_rows')->value('fields'));

        $this->export(1);
        $this->assertSame(1, DB::table('gift_export_rows')->count());
    }

    /** A worker that died leaves a run the screen must stop waiting on. */
    public function test_a_run_nobody_is_moving_is_marked_failed(): void
    {
        Queue::fake();
        $this->subscriber('Dana', succeeded: 3, customerId: '0');

        $latest = Tenant::run($this->shop, function (): ?GiftExportRun {
            $run = app(GiftExportRunner::class)->start($this->shop, 1, [], []);
            GiftExportRun::query()->whereKey($run->getKey())->update([
                'updated_at' => now()->subMinutes(GiftExportRunner::STALE_MINUTES + 1),
            ]);

            return app(GiftExportRunner::class)->latest();
        });

        $this->assertSame(GiftExportRun::STATUS_FAILED, $latest?->status);
    }

    public function test_someone_below_the_threshold_is_not_in_the_file(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->subscriber('Loyal', succeeded: 4, customerId: '0');
        $this->subscriber('Newcomer', succeeded: 1, customerId: '0', email: 'new@example.com');

        $csv = $this->export(3);

        $this->assertStringContainsString('Loyal', $csv);
        $this->assertStringNotContainsString('Newcomer', $csv);
    }

    // === Helpers ===

    /** An export end to end on the sync queue, returned as the file. */
    private function export(int $minCycles): string
    {
        return Tenant::run($this->shop, function () use ($minCycles): string {
            $run = app(GiftExportRunner::class)->start($this->shop, $minCycles, [], []);
            $this->assertSame(GiftExportRun::STATUS_COMPLETED, $run->status);

            return app(GiftListExporter::class)->file($run);
        });
    }

    /** @return list<list<string>> */
    private function rows(string $csv): array
    {
        $lines = preg_split('/\r?\n/', trim(substr($csv, 3))) ?: [];

        return array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines);
    }

    // === Fixtures ===

    /** Bulk — a fixture of hundreds is about the size, not about each subscriber. */
    private function manySubscribers(int $count): void
    {
        Tenant::run($this->shop, function () use ($count): void {
            $now = now();
            $plans = [];
            for ($i = 1; $i <= $count; $i++) {
                $plans[] = [
                    'shop_id' => $this->shop->getKey(),
                    'plan_kind' => PlanKind::RECURRING->value,
                    'charge_context' => 'recurring',
                    'status' => PlanStatus::ACTIVE->value,
                    'total_amount' => 100,
                    'installment_amount' => 100,
                    'currency' => 'ILS',
                    'public_id' => (string) Str::ulid(),
                    'customer_name' => 'Subscriber '.$i,
                    'customer_email' => 'sub'.$i.'@example.com',
                    'external_customer_id' => '0',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            InstallmentPlan::query()->insert($plans);

            $payments = [];
            foreach (InstallmentPlan::query()->pluck('id') as $planId) {
                $payments[] = [
                    'shop_id' => $this->shop->getKey(),
                    'plan_id' => $planId,
                    'payment_type' => PaymentType::RECURRING->value,
                    'sequence' => 1,
                    'amount' => 100,
                    'currency' => 'ILS',
                    'status' => PaymentStatus::SUCCEEDED->value,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            InstallmentPayment::query()->insert($payments);
        });
    }

    private function subscriber(
        string $name,
        int $succeeded,
        string $customerId,
        string $email = 'dana@example.com',
        ?string $orderId = null,
    ): void {
        Tenant::run($this->shop, function () use ($name, $succeeded, $customerId, $email, $orderId): void {
            $plan = new InstallmentPlan;
            $plan->fill([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                'total_amount' => 100,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'public_id' => (string) Str::ulid(),
                'customer_name' => $name,
                'customer_email' => $email,
                'external_customer_id' => $customerId,
            ]);
            $plan->forceFill([
                'shop_id' => (int) $this->shop->getKey(),
                'status' => PlanStatus::ACTIVE->value,
                'external_order_id' => $orderId,
            ])->save();

            for ($i = 1; $i <= $succeeded; $i++) {
                $payment = new InstallmentPayment;
                $payment->forceFill([
                    'shop_id' => (int) $this->shop->getKey(),
                    'plan_id' => $plan->getKey(),
                    'payment_type' => PaymentType::RECURRING->value,
                    'sequence' => $i,
                    'amount' => 100,
                    'currency' => 'ILS',
                    'status' => PaymentStatus::SUCCEEDED->value,
                ])->save();
            }
        });
    }
}
