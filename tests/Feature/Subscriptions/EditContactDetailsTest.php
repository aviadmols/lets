<?php

namespace Tests\Feature\Subscriptions;

use App\Domain\Import\SubscriptionExporter;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The subscription's own record of WHO it reaches.
 *
 * For an imported member this is the only address that exists anywhere — their
 * legacy person-id resolves to no store account — so the detail page must show
 * it and an edit must survive a round trip: the plan columns, the meta key, the
 * Timeline, and the CSV export all have to agree. The import's own copy under
 * meta.import.address is an audit trail and is never rewritten.
 */
final class EditContactDetailsTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const IMPORTED_ADDRESS = [
        'street' => 'אליהו הנביא',
        'building_number' => '18',
        'apartment_number' => '15',
        'city' => 'קרית אתא',
        'zip_code' => '2807361',
        'country' => 'ישראל',
    ];

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'contact.example.com',
            'name' => 'Contact',
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

    public function test_an_imported_address_is_shown_on_the_detail_page(): void
    {
        $plan = $this->importedPlan();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertSee('אליהו הנביא 18')
            ->assertSee('קרית אתא');
    }

    public function test_editing_writes_the_columns_and_the_contact_meta_but_not_the_import_audit(): void
    {
        $plan = $this->importedPlan();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->callAction('editContact', [
                'customer_name' => 'Motti Assaraf',
                'customer_email' => 'motti@example.com',
                'customer_phone' => '+972500000000',
                'street' => 'הרצל',
                'building_number' => '7',
                'apartment_number' => '',
                'city' => 'חיפה',
                'zip_code' => '3300000',
                'country' => 'ישראל',
            ]);

        $plan->refresh();

        $this->assertSame('Motti Assaraf', $plan->customer_name);
        $this->assertSame('motti@example.com', $plan->customer_email);

        // The merged view is the edited copy…
        $this->assertSame('הרצל', $plan->contactAddress()['street'] ?? null);
        $this->assertArrayNotHasKey('apartment_number', $plan->contactAddress());

        // …while the file's own copy stays exactly what the file said.
        $this->assertSame(
            self::IMPORTED_ADDRESS,
            (array) ($plan->meta['import']['address'] ?? []),
        );
    }

    public function test_the_edit_lands_on_the_timeline(): void
    {
        $plan = $this->importedPlan();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->callAction('editContact', [
                'customer_name' => 'New Name',
                'customer_email' => 'new@example.com',
                'customer_phone' => '',
                'street' => '', 'building_number' => '', 'apartment_number' => '',
                'city' => '', 'zip_code' => '', 'country' => '',
            ]);

        $event = ActivityEvent::query()
            ->where('plan_id', $plan->getKey())
            ->where('kind', 'customer_details_updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
    }

    /** An address the merchant corrected must ride the next export, not the stale import copy. */
    public function test_the_export_carries_the_edited_address(): void
    {
        $plan = $this->importedPlan();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->callAction('editContact', [
                'customer_name' => 'Motti Assaraf',
                'customer_email' => 'motti@example.com',
                'customer_phone' => '',
                'street' => 'הרצל',
                'building_number' => '7',
                'apartment_number' => '',
                'city' => 'חיפה',
                'zip_code' => '3300000',
                'country' => 'ישראל',
            ]);

        $path = tempnam(sys_get_temp_dir(), 'lets-export-');
        app(SubscriptionExporter::class)->toFile($this->shop, $path);
        $csv = file_get_contents($path);
        unlink($path);

        $this->assertStringContainsString('הרצל', $csv);
        $this->assertStringContainsString('חיפה', $csv);
        $this->assertStringNotContainsString('קרית אתא', $csv);
    }

    /** No import block at all: the card still renders, with em-dashes, and edits still save. */
    public function test_a_checkout_plan_without_an_import_block_can_gain_an_address(): void
    {
        $plan = $this->importedPlan(withImportMeta: false);

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->callAction('editContact', [
                'customer_name' => 'Guest Person',
                'customer_email' => 'guest@example.com',
                'customer_phone' => '',
                'street' => 'ביאליק',
                'building_number' => '3',
                'apartment_number' => '',
                'city' => 'רמת גן',
                'zip_code' => '',
                'country' => '',
            ]);

        $plan->refresh();

        $this->assertSame('ביאליק', $plan->contactAddress()['street'] ?? null);
        $this->assertSame('רמת גן', $plan->contactAddress()['city'] ?? null);
    }

    private function importedPlan(bool $withImportMeta = true): InstallmentPlan
    {
        $meta = $withImportMeta
            ? ['import' => ['membership_id' => 'mem-1', 'address' => self::IMPORTED_ADDRESS]]
            : [];

        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-contact-'.uniqid('', true),
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'customer_name' => 'Motti Assaraf',
            'customer_email' => 'mottiassaraf@gmail.com',
            'customer_phone' => '+972526650650',
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 579,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::YEARLY->value,
            'interval_count' => 1,
            'meta' => $meta,
        ])->save();

        return $plan;
    }
}
