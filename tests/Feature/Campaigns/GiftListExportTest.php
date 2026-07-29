<?php

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\GiftListExporter;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The list as a spreadsheet, so a merchant can ship the gifts by hand.
 *
 * It answers one question per row: where would this person's package go? Which
 * means a recipient with nowhere to send it is still a row, carrying the reason —
 * a file that quietly omits them looks complete and is not.
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

    public function test_each_qualifier_is_a_row_with_their_current_address(): void
    {
        Http::fake([
            '*/wp-json/wc/v3/customers/55' => Http::response([
                'id' => 55,
                'shipping' => [
                    'first_name' => 'דנה', 'last_name' => 'קונה',
                    'address_1' => 'הרצל 1', 'city' => 'תל אביב',
                    'postcode' => '6100000', 'country' => 'IL', 'phone' => '0501234567',
                ],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $this->subscriber('דנה קונה', succeeded: 3, customerId: '55');

        $csv = app(GiftListExporter::class)->csv($this->shop, 1);

        $this->assertStringContainsString('דנה קונה', $csv);
        $this->assertStringContainsString('הרצל 1', $csv);
        $this->assertStringContainsString('תל אביב', $csv);
        $this->assertStringContainsString('6100000', $csv);
        // Where the address came from, so a merchant can judge how fresh it is.
        $this->assertStringContainsString(__('gifts.export.source.customer_profile'), $csv);
    }

    public function test_a_recipient_with_nowhere_to_ship_is_still_listed(): void
    {
        Http::fake([
            // A profile with a country and nothing to deliver to.
            '*/wp-json/wc/v3/customers/55' => Http::response(['id' => 55, 'shipping' => ['country' => 'IL']], 200),
            '*' => Http::response([], 200),
        ]);

        $this->subscriber('Dana', succeeded: 3, customerId: '55');

        $csv = app(GiftListExporter::class)->csv($this->shop, 1);

        $this->assertStringContainsString('Dana', $csv);
        // The people who need attention are exactly the ones a filtered file hides.
        $this->assertStringContainsString(__('gifts.reason.no_address'), $csv);
    }

    public function test_the_file_opens_correctly_in_excel(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->subscriber('דנה', succeeded: 3, customerId: '0');

        $csv = app(GiftListExporter::class)->csv($this->shop, 1);

        // Without the BOM, Excel on a Hebrew Windows reads UTF-8 as the ANSI
        // codepage and every Hebrew name arrives as mojibake.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString(__('gifts.export.col.address1'), $csv);
    }

    public function test_exporting_creates_nothing(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->subscriber('Dana', succeeded: 3, customerId: '0');

        app(GiftListExporter::class)->csv($this->shop, 1);

        // Reading a list is not a campaign: no enrolment, no order, no gift.
        Tenant::run($this->shop, function (): void {
            $this->assertSame(0, \App\Domain\Campaigns\Models\GiftRecipient::query()->count());
            $this->assertSame(0, \App\Domain\Campaigns\Models\GiftCampaign::query()->count());
        });

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/wp-json/wc/v3/orders'));
    }

    public function test_a_huge_list_is_bounded_and_says_so(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->manySubscribers(GiftListExporter::MAX_ROWS + 20);

        $csv = app(GiftListExporter::class)->csv($this->shop, 1);

        // Header + at most MAX_ROWS recipients + the closing note.
        $lines = substr_count(rtrim($csv), "\n") + 1;
        $this->assertLessThanOrEqual(GiftListExporter::MAX_ROWS + 2, $lines);

        // Each row costs a live store read, so the file is bounded — by count and
        // by a time budget. What it must never do is look complete when it is not.
        [$before, $after] = explode('{n}', __('gifts.export.truncated', ['count' => '{n}']));
        $this->assertStringContainsString(trim($before) ?: $after, $csv);
        $this->assertStringContainsString(trim($after) ?: $before, $csv);
    }

    public function test_someone_below_the_threshold_is_not_in_the_file(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->subscriber('Loyal', succeeded: 4, customerId: '0');
        $this->subscriber('Newcomer', succeeded: 1, customerId: '0', email: 'new@example.com');

        $csv = app(GiftListExporter::class)->csv($this->shop, 3);

        $this->assertStringContainsString('Loyal', $csv);
        $this->assertStringNotContainsString('Newcomer', $csv);
    }

    // === Fixtures ===

    /** Bulk — a fixture of hundreds is about the bound, not about each subscriber. */
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

    private function subscriber(string $name, int $succeeded, string $customerId, string $email = 'dana@example.com'): void
    {
        Tenant::run($this->shop, function () use ($name, $succeeded, $customerId, $email): void {
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
