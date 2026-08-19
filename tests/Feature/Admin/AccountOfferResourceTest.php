<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\AccountOfferResource;
use App\Filament\Resources\AccountOfferResource\Pages\CreateAccountOffer;
use App\Filament\Resources\AccountOfferResource\Pages\EditAccountOffer;
use App\Filament\Resources\AccountOfferResource\Pages\ListAccountOffers;
use App\Models\AccountOffer;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;
use App\Support\Tenant;
use App\Support\Ui\EmbeddedMenu;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Account\Offers\MakesAccountOffers;
use Tests\TestCase;

/**
 * The merchant's side of an account offer: the Filament screen that writes the
 * row every other part of this feature reads.
 *
 * Three things are asserted rather than assumed, because each is a way for this
 * screen to do damage the storefront cannot undo:
 *
 *   TENANCY — the row is stamped with the BOUND shop and another shop's offer is
 *   a 404, not a permission notice. An offer is a charge instruction; editing one
 *   across a tenant boundary would charge somebody else's customers.
 *
 *   THE AUDIENCE BAG — four checkbox groups that must land in the JSON shape the
 *   eligibility reader expects. A frequency written into the wrong key does not
 *   error; it silently widens the offer to everybody.
 *
 *   CUSTOM HTML — `{{button}}` is refused when missing (a promotion nobody can
 *   accept) and `<script>` never reaches the database at all (the block ends up
 *   inside a signed-in shopper's account page).
 */
final class AccountOfferResourceTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    private Shop $shopA;

    private Shop $shopB;

    private ProductSubscriptionPlan $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopA = $this->makeShop('offers-admin-a.example.com');
        $this->shopB = $this->makeShop('offers-admin-b.example.com');

        Tenant::set($this->shopA);
        $this->actingAs(User::factory()->forShop($this->shopA)->create());

        $this->template = $this->makeTemplate(
            $this->makeProduct(self::PRODUCT_MONTHLY, 'Coffee club', 89.0),
            BillingFrequency::MONTHLY,
        );
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === The screen itself ===

    public function test_the_list_renders_under_the_upsell_group(): void
    {
        Livewire::test(ListAccountOffers::class)->assertOk();

        $this->assertSame(__('nav.group.upsell'), AccountOfferResource::getNavigationGroup());
        $this->assertSame(__('account_offers.nav.label'), AccountOfferResource::getNavigationLabel());
        $this->assertSame(
            EmbeddedMenu::AREA_UPSELL,
            EmbeddedMenu::areaFor(AccountOfferResource::class),
            'the embedded menu must know where this screen lives',
        );
    }

    /** No bound shop, no screen — the fail-closed gate every per-shop screen shares. */
    public function test_the_resource_is_hidden_without_a_bound_tenant(): void
    {
        $this->assertTrue(AccountOfferResource::canAccess());

        Tenant::clear();

        $this->assertFalse(AccountOfferResource::canAccess());
        $this->assertFalse(AccountOfferResource::shouldRegisterNavigation());
    }

    /**
     * Only what can actually be sold one-click is pickable: a Shopify-rail
     * template bills on a rail we hold no token for, so an offer pointing at one
     * could only ever produce a button that fails.
     */
    public function test_the_template_picker_lists_only_sellable_payplus_templates(): void
    {
        $draft = $this->makeTemplate(
            $this->makeProduct('9001', 'Draft box', 40.0),
            status: PlanTemplateStatus::DRAFT,
        );
        $shopifyRail = $this->makeTemplate(
            $this->makeProduct('9002', 'Shopify box', 40.0),
            rail: ProductSubscriptionPlan::RAIL_SHOPIFY_PAYMENTS,
        );

        $options = AccountOfferResource::templateOptions();

        $this->assertArrayHasKey($this->template->getKey(), $options);
        $this->assertArrayNotHasKey($draft->getKey(), $options);
        $this->assertArrayNotHasKey($shopifyRail->getKey(), $options);

        // "product — cadence — price", from the template, never typed here.
        $this->assertStringContainsString('Coffee club', $options[$this->template->getKey()]);
        $this->assertStringContainsString(
            __('billing.settings.frequency.monthly'),
            $options[$this->template->getKey()],
        );
    }

    // === Create ===

    public function test_creating_stamps_the_bound_shop_and_shapes_the_audience_bag(): void
    {
        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData([
                'audience' => [
                    'plan_kinds' => [PlanKind::RECURRING->value],
                    'frequencies' => [BillingFrequency::YEARLY->value],
                    'product_ids' => [],
                    'statuses' => [PlanStatus::ACTIVE->value, PlanStatus::PAUSED->value],
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $offer = AccountOffer::query()->where('name', 'Switch to monthly')->firstOrFail();

        $this->assertSame((int) $this->shopA->getKey(), (int) $offer->shop_id, 'stamped, never taken from input');
        $this->assertSame((int) $this->template->getKey(), (int) $offer->product_subscription_plan_id);
        $this->assertSame(AccountOffer::MODE_REPLACE, $offer->mode());
        $this->assertSame(AccountOffer::TIMING_PERIOD_END, $offer->timing());
        $this->assertSame(AccountOffer::PLACEMENT_TOP, $offer->placement());

        // The JSON shape the eligibility reader expects: every key present, each
        // an inclusive list, empty meaning "anyone".
        $this->assertSame(AccountOffer::AUDIENCE_KEYS, array_keys((array) $offer->audience));
        $this->assertSame([BillingFrequency::YEARLY->value], $offer->audience()['frequencies']);
        $this->assertSame([PlanKind::RECURRING->value], $offer->audience()['plan_kinds']);
        $this->assertSame([], $offer->audience()['product_ids'], 'no products named = any product');
        $this->assertSame(
            [PlanStatus::ACTIVE->value, PlanStatus::PAUSED->value],
            $offer->audience()['statuses'],
        );
    }

    /** A shop_id posted by hand must not move an offer to another tenant. */
    public function test_a_posted_shop_id_is_ignored(): void
    {
        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData(['name' => 'Smuggled']))
            ->call('create')
            ->assertHasNoFormErrors();

        $offer = AccountOffer::query()->where('name', 'Smuggled')->firstOrFail();

        // shop_id is guarded on the model AND absent from the schema; assert the
        // outcome rather than the mechanism.
        $this->assertSame((int) $this->shopA->getKey(), (int) $offer->shop_id);
    }

    // === Edit ===

    public function test_editing_round_trips_the_audience_bag(): void
    {
        $offer = $this->makeOffer($this->template, [
            'name' => 'Yearly members',
            'audience' => [
                'plan_kinds' => [PlanKind::RECURRING->value],
                'frequencies' => [BillingFrequency::YEARLY->value],
                'product_ids' => [self::PRODUCT_YEARLY],
                'statuses' => [PlanStatus::ACTIVE->value],
            ],
        ]);

        Livewire::test(EditAccountOffer::class, ['record' => $offer->getKey()])
            ->assertOk()
            // What was stored comes back into the form, key for key.
            ->assertFormSet([
                'audience.frequencies' => [BillingFrequency::YEARLY->value],
                'audience.product_ids' => [self::PRODUCT_YEARLY],
                'audience.statuses' => [PlanStatus::ACTIVE->value],
            ])
            // Widen it: drop the product filter, add the paused subscribers.
            ->fillForm([
                'audience.product_ids' => [],
                'audience.statuses' => [PlanStatus::ACTIVE->value, PlanStatus::PAUSED->value],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = $offer->fresh();

        $this->assertSame([], $saved->audience()['product_ids'], 'an emptied filter stops narrowing');
        $this->assertSame(
            [PlanStatus::ACTIVE->value, PlanStatus::PAUSED->value],
            $saved->audience()['statuses'],
        );
        $this->assertSame([BillingFrequency::YEARLY->value], $saved->audience()['frequencies'], 'untouched, unchanged');
    }

    /** Switching to ADD clears a timing that would otherwise linger invisibly. */
    public function test_switching_to_add_drops_the_replacement_timing(): void
    {
        $offer = $this->makeOffer($this->template, [
            'mode' => AccountOffer::MODE_REPLACE,
            'replace_timing' => AccountOffer::TIMING_PERIOD_END,
        ]);

        Livewire::test(EditAccountOffer::class, ['record' => $offer->getKey()])
            ->fillForm(['mode' => AccountOffer::MODE_ADD])
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = $offer->fresh();

        $this->assertNull($saved->replace_timing);
        $this->assertNull($saved->timing(), 'an ADD offer ends no period, so it reports no timing');
    }

    // === Custom HTML ===

    public function test_custom_html_without_the_button_token_is_refused(): void
    {
        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData([
                'custom_html' => '<p>Switch to monthly and save</p>',
            ]))
            ->call('create')
            ->assertHasFormErrors(['custom_html']);

        $this->assertSame(0, AccountOffer::query()->count(), 'nothing is written when the block cannot be accepted');
    }

    /** Twice is as unusable as none: two controls wired to the same charge. */
    public function test_custom_html_with_two_button_tokens_is_refused(): void
    {
        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData([
                'custom_html' => '<p>{{button}}</p><p>{{button}}</p>',
            ]))
            ->call('create')
            ->assertHasFormErrors(['custom_html']);
    }

    public function test_custom_html_is_stored_with_the_script_stripped(): void
    {
        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData([
                'custom_html' => '<p onclick="steal()">Only <strong>{{price}}</strong> {{button}}</p>'
                    .'<script>alert(document.cookie)</script>',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $stored = (string) AccountOffer::query()->where('name', 'Switch to monthly')->firstOrFail()->custom_html;

        // The hostile version never exists AT REST, not merely on the way out.
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('alert(', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringContainsString('{{button}}', $stored, 'the token survives the sanitiser as text');
        $this->assertStringContainsString('<strong>', $stored, 'the merchant keeps their markup');
    }

    // === Tenancy ===

    public function test_another_shops_offer_is_neither_listed_nor_editable(): void
    {
        $mine = $this->makeOffer($this->template, ['name' => 'Mine']);

        $theirs = Tenant::run($this->shopB, function (): AccountOffer {
            $template = $this->makeTemplate($this->makeProduct('7777', 'Their box', 55.0));

            return $this->makeOffer($template, ['name' => 'Theirs']);
        });

        Tenant::set($this->shopA);

        Livewire::test(ListAccountOffers::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        // Not a permission message — the row does not exist for this tenant.
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(EditAccountOffer::class, ['record' => $theirs->getKey()]);
    }

    // === Helpers ===

    /**
     * A complete, valid form payload. Individual tests override the one field
     * they are about, so a failure names the thing that broke.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function formData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Switch to monthly',
            'status' => AccountOffer::STATUS_ACTIVE,
            'product_subscription_plan_id' => $this->template->getKey(),
            'mode' => AccountOffer::MODE_REPLACE,
            'replace_timing' => AccountOffer::TIMING_PERIOD_END,
            'placement' => AccountOffer::PLACEMENT_TOP,
            'priority' => 0,
            'shop_id' => $this->shopB->getKey(), // ignored: guarded + not in the schema
        ], $overrides);
    }
}
