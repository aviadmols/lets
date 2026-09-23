<?php

namespace Tests\Feature\Subscriptions;

use App\Domain\Addresses\AddressRegistry;
use App\Domain\Campaigns\GiftShippingAddress;
use App\Domain\Installments\ManualSubscriptionService;
use App\Domain\Installments\RecurringPlanService;
use App\Filament\Resources\SubscriptionContractResource\Pages\ListSubscriptionContracts;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adding a subscriber by hand — and the promise that nothing will ever charge them.
 *
 * The screen makes a claim in its own copy ("no card is attached and no charge is
 * ever scheduled"), and these tests are what keeps that claim true. Each one pins
 * a separate wall, because the whole design is that no single one of them is
 * trusted: no charge date, no vaulted card, and — the one that survives a later
 * well-meant edit — no consent row, which is the gate the orchestrator itself
 * refuses on.
 */
final class CreateSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'manual.example.com',
            'name' => 'Manual',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        /*
         * The address section asks the locality registry whether it can offer a
         * closed list. Faked as UNREACHABLE here, for two reasons: building a
         * form must never depend on somebody else's uptime in a test run, and
         * the free-text fallback is the shape the address tests below type into.
         * The list itself is pinned in AddressRegistryTest.
         */
        Http::fake([AddressRegistry::GOV_API.'*' => Http::response('', 503)]);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /** The screen writes a real subscription — with every road to a charge closed. */
    public function test_the_form_creates_a_subscription_that_cannot_be_charged(): void
    {
        Livewire::test(ListSubscriptions::class)
            ->callAction('newSubscription', $this->formData())
            ->assertHasNoActionErrors();

        $plan = InstallmentPlan::query()->firstOrFail();

        $this->assertSame((int) $this->shop->getKey(), (int) $plan->shop_id);
        $this->assertSame(PlanStatus::ACTIVE, $plan->status);
        $this->assertSame(PlanKind::RECURRING, $plan->plan_kind);
        $this->assertSame('Dana Levi', $plan->customer_name);
        $this->assertSame('dana@example.com', $plan->customer_email);

        // WALL 1 — nothing to schedule.
        $this->assertNull($plan->next_charge_at);
        // WALL 2 — nothing to charge with.
        $this->assertNull($plan->payment_method_id);
        // WALL 3 — and nobody agreed to be charged, which is what the
        // orchestrator asks before it reaches PayPlus.
        $this->assertDatabaseCount('customer_consents', 0);

        // No money was invented on the way in, either.
        $this->assertDatabaseCount('payment_ledger', 0);
        $this->assertDatabaseCount('installment_payments', 0);
    }

    /**
     * The merchant lands on what they just made.
     *
     * Also the cheapest possible pin on the detail page itself: a subscription
     * with no card, no charge date and no order is a shape that screen never saw
     * from a checkout, and it has to open without falling over.
     */
    public function test_it_opens_the_subscription_it_just_created(): void
    {
        Livewire::test(ListSubscriptions::class)
            ->callAction('newSubscription', $this->formData())
            ->assertHasNoActionErrors();

        $plan = InstallmentPlan::query()->firstOrFail();

        Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
            ->assertOk()
            // The one action that moves money stays hidden: there is no card.
            ->assertActionHidden('chargeNow');
    }

    /** Zero is the ordinary case here, and the one every checkout path refuses. */
    public function test_a_free_subscription_is_legal_on_this_path(): void
    {
        $plan = $this->create(['amount' => 0]);

        $this->assertSame(0.0, round((float) $plan->installment_amount, 2));
        $this->assertSame(0.0, round((float) $plan->total_amount, 2));

        // The sibling service still refuses it — this path is an exception, not
        // a loosening of the rule.
        $this->expectException(\RuntimeException::class);
        app(RecurringPlanService::class)->createForCustomer($this->shop, [
            'product_gid' => 'gid://shopify/Product/1',
            'variant_gid' => 'gid://shopify/ProductVariant/1',
            'amount' => 0,
            'frequency' => BillingFrequency::MONTHLY,
            'currency' => 'ILS',
        ]);
    }

    /** The scheduler does not merely skip it — it never selects it. */
    public function test_the_scheduler_never_queues_a_hand_typed_subscription(): void
    {
        Queue::fake();

        $this->create();

        $this->travel(2)->months();

        Tenant::clear(); // the state the scheduler really runs in
        $this->artisan('payplus:dispatch-due')->assertSuccessful();

        Queue::assertNotPushed(ChargeJob::class);
    }

    /**
     * A second subscription for someone we already know lands on THEIR customer,
     * not beside it. There is no customers table — the reference on their
     * existing plan is the only thing that joins the two.
     */
    public function test_it_joins_the_customer_that_email_already_belongs_to(): void
    {
        $existing = new InstallmentPlan;
        $existing->fill([
            'plan_kind' => PlanKind::RECURRING->value,
            'customer_email' => 'Dana@Example.com', // the same person, typed differently
            'shopify_customer_id' => '55120',
            'customer_id' => 4711,
            'installment_amount' => 90,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'public_id' => 'existing-plan',
        ]);
        $existing->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
        ])->save();

        $plan = $this->create();

        $this->assertSame('55120', $plan->shopify_customer_id);
        $this->assertSame(4711, (int) $plan->customer_id);
    }

    /** An unknown email opens a customer of its own rather than borrowing one. */
    public function test_an_unknown_email_carries_no_borrowed_identity(): void
    {
        $plan = $this->create(['customer_email' => 'nobody@example.com']);

        $this->assertNull($plan->shopify_customer_id);
        $this->assertNull($plan->customer_id);
    }

    /** A catalog product carries its ids onto the plan, so the row is not an orphan. */
    public function test_a_picked_product_lands_on_the_plan(): void
    {
        $product = Product::query()->create([
            'source' => Product::SOURCE_WOOCOMMERCE,
            'external_id' => '8801',
            'title' => 'Coffee club',
            'status' => Product::STATUS_ACTIVE,
        ]);

        $product->variants()->create([
            'external_variant_id' => '9901',
            'title' => 'Monthly bag',
            'price' => 90,
            'position' => 1,
        ]);

        $plan = $this->create(['product_external_id' => '8801', 'item_title' => null]);

        $this->assertSame('8801', $plan->external_product_id);
        $this->assertSame('9901', $plan->external_variant_id);
        // With no name typed, the product names the subscription.
        $this->assertSame('Coffee club', $plan->meta[InstallmentPlan::META_ITEM_TITLE] ?? null);
    }

    /** A shop billing in dollars does not get one ILS plan wedged among them. */
    public function test_the_amount_is_written_in_the_currency_the_shop_already_bills_in(): void
    {
        $existing = new InstallmentPlan;
        $existing->fill([
            'plan_kind' => PlanKind::RECURRING->value,
            'customer_email' => 'someone@example.com',
            'installment_amount' => 30,
            'currency' => 'USD',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'public_id' => 'usd-plan',
        ]);
        $existing->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
        ])->save();

        Livewire::test(ListSubscriptions::class)
            ->callAction('newSubscription', $this->formData())
            ->assertHasNoActionErrors();

        $plan = InstallmentPlan::query()->where('public_id', '!=', 'usd-plan')->firstOrFail();

        $this->assertSame('USD', $plan->currency);
    }

    /**
     * The address a merchant types here is the one the store's checkout asks
     * for — every field of it, floor and entrance included — and it lands where
     * an admin EDIT would have put it, not where an import would.
     *
     * That distinction is the whole point: meta.import.address is the audit
     * trail of what a migration file said and is never rewritten, so an address
     * stored there would be invisible to the "Edit contact details" form the
     * merchant reaches for next.
     */
    public function test_it_stores_the_whole_checkout_address(): void
    {
        $address = [
            'street' => 'אליהו הנביא',
            'building_number' => '18',
            'apartment_number' => '4',
            'floor' => '2',
            'entrance' => 'ב',
            'city' => 'חיפה',
            'zip_code' => '3521234',
            'country' => 'IL',
        ];

        Livewire::test(ListSubscriptions::class)
            ->callAction('newSubscription', array_merge($this->formData(), ['address' => $address]))
            ->assertHasNoActionErrors();

        $plan = InstallmentPlan::query()->firstOrFail();

        $this->assertSame($address, $plan->contactAddress());
        $this->assertSame(
            $address,
            $plan->meta[InstallmentPlan::META_CONTACT_ADDRESS] ?? null,
            'an address typed by a person is an admin edit, not an import record',
        );

        // The readable line names the parts instead of running the numbers
        // together — "4, 2, ב" between a street and a city is not an address.
        // Asserted through the translator, because the label a merchant sees is
        // whichever language they are reading the admin in.
        $line = $plan->contactAddressLine();
        $this->assertStringContainsString('אליהו הנביא 18', $line);
        $this->assertStringContainsString('חיפה', $line);

        foreach (InstallmentPlan::ADDRESS_LABELLED_PARTS as $field => $key) {
            $this->assertStringContainsString(
                (string) __($key, ['number' => $address[$field]]),
                $line,
                "the line labels {$field}",
            );
        }
    }

    /**
     * With the registry answering, the city and the street are a CLOSED LIST —
     * the same one the store's checkout offers — and what the merchant picks is
     * stored as the registry spells it.
     */
    public function test_the_city_and_street_come_from_the_registry_when_it_answers(): void
    {
        Http::fake([AddressRegistry::GOV_API.'*' => Http::sequence()
            ->push(['result' => ['records' => [
                [AddressRegistry::CITY_FIELD => 'חיפה', AddressRegistry::CITY_CODE_FIELD => 4000],
            ]]], 200)
            ->push(['result' => ['records' => [
                [AddressRegistry::STREET_FIELD => 'אליהו הנביא'],
            ]]], 200),
        ]);
        Cache::flush();

        Livewire::test(ListSubscriptions::class)
            ->callAction('newSubscription', array_merge($this->formData(), [
                'address' => ['city' => 'חיפה', 'street' => 'אליהו הנביא', 'building_number' => '18'],
            ]))
            ->assertHasNoActionErrors();

        $this->assertSame(
            ['street' => 'אליהו הנביא', 'building_number' => '18', 'city' => 'חיפה'],
            InstallmentPlan::query()->firstOrFail()->contactAddress(),
        );
    }

    /** A key the plan's address vocabulary does not know is dropped, not stored. */
    public function test_it_keeps_only_the_address_fields_it_knows(): void
    {
        $plan = $this->create([
            'address' => ['city' => 'חיפה', 'unknown_field' => 'x', 'street' => '   '],
        ]);

        $this->assertSame(['city' => 'חיפה'], $plan->contactAddress());
        $this->assertArrayNotHasKey(
            'unknown_field',
            $plan->meta[InstallmentPlan::META_CONTACT_ADDRESS] ?? [],
        );
    }

    /** It ships: the courier sheet gets the floor and the entrance too. */
    public function test_the_address_reaches_a_shipping_block(): void
    {
        $plan = $this->create([
            'address' => [
                'street' => 'אליהו הנביא',
                'building_number' => '18',
                'apartment_number' => '4',
                'floor' => '2',
                'entrance' => 'ב',
                'city' => 'חיפה',
            ],
        ]);

        $shipping = GiftShippingAddress::fromPlanContact($plan);

        $this->assertNotNull($shipping);
        $this->assertSame('2', $shipping->floor);
        $this->assertSame('ב', $shipping->entrance);
        $this->assertSame('18', $shipping->building);
        $this->assertSame('חיפה', $shipping->city);
    }

    /** The timeline says how this subscription got here — a sale is not implied. */
    public function test_it_writes_its_own_timeline_event(): void
    {
        $plan = $this->create();

        $event = ActivityEvent::query()
            ->where('plan_id', $plan->getKey())
            ->where('kind', ManualSubscriptionService::KIND_CREATED)
            ->firstOrFail();

        $this->assertSame((int) $this->shop->getKey(), (int) $event->shop_id);
        $this->assertSame(ManualSubscriptionService::SOURCE, $event->details['source'] ?? null);
    }

    /**
     * A status this path may not create is not quietly honoured. Fail-closed
     * matters here: awaiting_first_payment would put the plan in the scheduler's
     * chargeable set on the strength of a payment that is not coming.
     */
    public function test_a_status_outside_the_birth_set_falls_back_to_active(): void
    {
        $plan = $this->create(['status' => PlanStatus::AWAITING_FIRST_PAYMENT->value]);

        $this->assertSame(PlanStatus::ACTIVE, $plan->status);
    }

    /** Draft is the other legal birth — recorded, not started. */
    public function test_it_can_be_created_as_a_draft(): void
    {
        $plan = $this->create(['status' => PlanStatus::DRAFT->value]);

        $this->assertSame(PlanStatus::DRAFT, $plan->status);
        $this->assertNull($plan->next_charge_at);
    }

    /**
     * A shop that bills through SHOPIFY can add one too.
     *
     * Its subscriptions list hides itself while it holds no PayPlus plans, which
     * left the only screen carrying this button invisible exactly when it was
     * needed — you could not create the first plan because there was no first
     * plan. The contracts list, which such a shop DOES see, offers the same
     * button; pressing it also makes the subscriptions list appear.
     */
    public function test_a_shopify_rail_shop_can_add_one_from_its_contracts_screen(): void
    {
        $shopify = Shop::create([
            'shopify_domain' => 'rail.myshopify.com',
            'name' => 'Rail',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);

        Tenant::set($shopify);
        $this->actingAs(User::factory()->forShop($shopify)->create());

        Livewire::test(ListSubscriptionContracts::class)
            ->callAction('newSubscription', $this->formData())
            ->assertHasNoActionErrors();

        $plan = InstallmentPlan::query()->firstOrFail();

        $this->assertSame((int) $shopify->getKey(), (int) $plan->shop_id);
        $this->assertTrue((bool) $plan->no_charge);
        $this->assertNull($plan->next_charge_at);
    }

    /** One shop's hand-typed subscriber never appears in another's list. */
    public function test_the_plan_belongs_to_the_bound_tenant_only(): void
    {
        $other = Shop::create([
            'woocommerce_domain' => 'other.example.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $this->create();

        Tenant::run($other, function (): void {
            $this->assertSame(0, InstallmentPlan::query()->count());
        });
    }

    /** @param array<string, mixed> $overrides */
    private function create(array $overrides = []): InstallmentPlan
    {
        return app(ManualSubscriptionService::class)->create(
            $this->shop,
            array_merge($this->formData(), $overrides),
        );
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'customer_name' => 'Dana Levi',
            'customer_email' => 'dana@example.com',
            'customer_phone' => '0501234567',
            'item_title' => 'Coffee club',
            'product_external_id' => null,
            'amount' => 0,
            'frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'status' => PlanStatus::ACTIVE->value,
            'note' => 'Comped for the pilot',
        ];
    }
}
