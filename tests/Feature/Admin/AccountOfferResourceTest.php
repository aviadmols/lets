<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\AccountOfferResource;
use App\Filament\Resources\AccountOfferResource\Pages\CreateAccountOffer;
use App\Filament\Resources\AccountOfferResource\Pages\EditAccountOffer;
use App\Filament\Resources\AccountOfferResource\Pages\ListAccountOffers;
use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
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
 * rows every other part of this feature reads.
 *
 * AN OFFER SELLS A LIST. The promotion lives on `account_offers` and every thing
 * it sells is an `account_offer_targets` row written by the form's repeater, so
 * the screen's job is no longer "pick a template" — it is "write a well-formed
 * list of charge instructions, in order".
 *
 * Four things are asserted rather than assumed, because each is a way for this
 * screen to do damage the storefront cannot undo:
 *
 *   TENANCY — the offer AND every target row are stamped with the BOUND shop, and
 *   another shop's offer is a 404, not a permission notice. An offer is a charge
 *   instruction; editing one across a tenant boundary would charge somebody
 *   else's customers.
 *
 *   THE TARGET ROWS — both kinds round-trip, `position` is the repeater's own
 *   1-based order (it is also what `{{button_2}}` addresses), and the columns
 *   that belong to the other kind are nulled rather than left lingering under a
 *   hidden control.
 *
 *   THE AUDIENCE BAG — four checkbox groups that must land in the JSON shape the
 *   eligibility reader expects. A frequency written into the wrong key does not
 *   error; it silently widens the offer to everybody.
 *
 *   CUSTOM HTML — a block with no button token is refused (a promotion nobody can
 *   accept), a token naming a target that does not exist is refused
 *   (AccountOffer::ERROR_BUTTON_UNKNOWN — otherwise the merchant learns about the
 *   typo from a shopper who could not buy the thing), and `<script>` never
 *   reaches the database at all (the block ends up inside a signed-in shopper's
 *   account page).
 */
final class AccountOfferResourceTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    // === CONSTANTS ===
    /** The repeater's Livewire state path — the tests write rows straight into it. */
    private const TARGETS_PATH = 'data.targets';

    /** A one-time catalog product to sell beside the subscription. */
    private const PRODUCT_MUG = '3001';

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

    /** Every merchant-facing string this screen shows exists in BOTH catalogs. */
    public function test_the_lang_catalogs_mirror_key_for_key(): void
    {
        $en = $this->flatten(require base_path('lang/en/account_offers.php'));
        $he = $this->flatten(require base_path('lang/he/account_offers.php'));

        $this->assertSame([], array_values(array_diff($en, $he)), 'lang/he/account_offers.php is missing EN keys');
        $this->assertSame([], array_values(array_diff($he, $en)), 'lang/he/account_offers.php has keys absent from EN');
    }

    /**
     * Only what can actually be sold one-click is pickable: a Shopify-rail
     * template bills on a rail we hold no token for, so a target pointing at one
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

    /** The one-time picker is the shop's own catalog, keyed by the platform id. */
    public function test_the_product_picker_lists_the_shops_catalog_by_external_id(): void
    {
        $this->makeProduct(self::PRODUCT_MUG, 'Branded mug', 45.0);

        $options = AccountOfferResource::catalogProductOptions();

        $this->assertArrayHasKey(self::PRODUCT_MUG, $options);
        $this->assertStringContainsString('Branded mug', $options[self::PRODUCT_MUG]);
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
            ->set(self::TARGETS_PATH, [$this->subscriptionRow()])
            ->call('create')
            ->assertHasNoFormErrors();

        $offer = AccountOffer::query()->where('name', 'Switch to monthly')->firstOrFail();

        $this->assertSame((int) $this->shopA->getKey(), (int) $offer->shop_id, 'stamped, never taken from input');
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

        // The target the offer actually sells.
        $targets = $offer->orderedTargets();
        $this->assertCount(1, $targets);
        $this->assertSame(1, $targets[0]->position(), 'position is 1-based');
        $this->assertSame((int) $this->shopA->getKey(), (int) $targets[0]->shop_id, 'the row carries its own tenancy');
        $this->assertSame((int) $this->template->getKey(), (int) $targets[0]->product_subscription_plan_id);
        $this->assertSame(AccountOfferTarget::MODE_REPLACE, $targets[0]->mode());
        $this->assertSame(AccountOfferTarget::TIMING_PERIOD_END, $targets[0]->timing());
    }

    /**
     * One promotion, two choices — the whole reason targets became a list. The
     * repeater's visual order IS `position`, which is also what `{{button_2}}`
     * addresses, so a wrong order here renames every button.
     */
    public function test_creating_writes_both_kinds_in_repeater_order(): void
    {
        $this->makeProduct(self::PRODUCT_MUG, 'Branded mug', 45.0);

        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData(['name' => 'Switch and add a mug']))
            ->set(self::TARGETS_PATH, [
                $this->subscriptionRow(['token_key' => 'upgrade']),
                $this->oneTimeRow(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $offer = AccountOffer::query()->where('name', 'Switch and add a mug')->firstOrFail();
        $targets = $offer->orderedTargets();

        $this->assertCount(2, $targets);

        [$subscription, $mug] = $targets;

        $this->assertSame(1, $subscription->position());
        $this->assertSame(AccountOfferTarget::KIND_SUBSCRIPTION, $subscription->kind());
        $this->assertSame('upgrade', $subscription->tokenKey());
        // A subscription target has no count, no fulfilment and no product id of
        // its own — the template is what it sells.
        $this->assertNull($subscription->fulfilment());
        $this->assertNull($subscription->externalProductId());
        $this->assertSame(1, $subscription->quantity());

        $this->assertSame(2, $mug->position());
        $this->assertSame(AccountOfferTarget::KIND_ONE_TIME, $mug->kind());
        $this->assertSame(self::PRODUCT_MUG, $mug->externalProductId());
        $this->assertSame(2, $mug->quantity());
        $this->assertSame(AccountOfferTarget::FULFILMENT_NEXT_ORDER, $mug->fulfilment());
        // A one-time product ends no period: always an ADD, never a timing, and
        // never a template.
        $this->assertSame(AccountOfferTarget::MODE_ADD, $mug->mode());
        $this->assertNull($mug->timing());
        $this->assertNull($mug->product_subscription_plan_id);

        // Both rows carry the bound shop, not just the parent.
        foreach ($targets as $target) {
            $this->assertSame((int) $this->shopA->getKey(), (int) $target->shop_id);
        }
    }

    /** A shop_id posted by hand must not move an offer to another tenant. */
    public function test_a_posted_shop_id_is_ignored(): void
    {
        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData(['name' => 'Smuggled']))
            ->set(self::TARGETS_PATH, [$this->subscriptionRow(['shop_id' => $this->shopB->getKey()])])
            ->call('create')
            ->assertHasNoFormErrors();

        $offer = AccountOffer::query()->where('name', 'Smuggled')->firstOrFail();

        // shop_id is guarded on both models AND absent from the schema; assert the
        // outcome rather than the mechanism.
        $this->assertSame((int) $this->shopA->getKey(), (int) $offer->shop_id);
        $this->assertSame((int) $this->shopA->getKey(), (int) $offer->orderedTargets()[0]->shop_id);
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

    /**
     * Editing keeps the row it had and appends the new one after it — the
     * existing target is UPDATED (same id), not deleted and re-created, because a
     * key that changes under a page the shopper already loaded is a click that
     * lands on the wrong thing.
     *
     * Switching that row to ADD also clears a timing that would otherwise linger
     * invisibly beneath a hidden control.
     */
    public function test_editing_updates_a_target_in_place_and_appends_the_next(): void
    {
        $this->makeProduct(self::PRODUCT_MUG, 'Branded mug', 45.0);

        $offer = $this->makeOffer($this->template, [
            'mode' => AccountOfferTarget::MODE_REPLACE,
            'replace_timing' => AccountOfferTarget::TIMING_PERIOD_END,
        ]);
        $original = $offer->orderedTargets()[0];

        Livewire::test(EditAccountOffer::class, ['record' => $offer->getKey()])
            ->set(self::TARGETS_PATH.'.record-'.$original->getKey().'.mode', AccountOfferTarget::MODE_ADD)
            ->set(self::TARGETS_PATH.'.new-mug', $this->oneTimeRow())
            ->call('save')
            ->assertHasNoFormErrors();

        $targets = $offer->fresh()->orderedTargets();

        $this->assertCount(2, $targets);
        $this->assertSame((int) $original->getKey(), (int) $targets[0]->getKey(), 'the row is updated, not replaced');
        $this->assertSame(1, $targets[0]->position());
        $this->assertSame(AccountOfferTarget::MODE_ADD, $targets[0]->mode());
        $this->assertNull($targets[0]->replace_timing, 'an ADD ends no period, so no timing is left behind');
        $this->assertNull($targets[0]->timing());

        $this->assertSame(2, $targets[1]->position());
        $this->assertSame(AccountOfferTarget::KIND_ONE_TIME, $targets[1]->kind());
        $this->assertSame((int) $this->shopA->getKey(), (int) $targets[1]->shop_id);
    }

    /**
     * Two rows answering to one token is an ambiguous button, which in this
     * feature means a charge for the wrong thing. The schema refuses it with a
     * unique index; the merchant deserves the sentence instead of the 500.
     */
    public function test_two_targets_may_not_share_a_token_name(): void
    {
        $this->makeProduct(self::PRODUCT_MUG, 'Branded mug', 45.0);

        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData(['name' => 'Ambiguous']))
            ->set(self::TARGETS_PATH, [
                $this->subscriptionRow(['token_key' => 'pick_me']),
                $this->oneTimeRow(['token_key' => 'pick_me']),
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, AccountOffer::query()->count());
        $this->assertSame(0, AccountOfferTarget::query()->count());
    }

    /**
     * A slug outside the grammar is refused rather than quietly dropped. The
     * model's tokenKey() guard would read it as "no name at all", which would
     * turn the merchant's own `{{button_Up Grade}}` into an unknown token on a
     * screen that had just told them the save went fine.
     */
    public function test_a_token_name_outside_the_grammar_is_refused(): void
    {
        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData(['name' => 'Bad slug']))
            ->set(self::TARGETS_PATH, [$this->subscriptionRow(['token_key' => 'Up Grade'])])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, AccountOffer::query()->count());
    }

    // === Custom HTML ===

    public function test_custom_html_without_a_button_token_is_refused(): void
    {
        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData([
                'custom_html' => '<p>Switch to monthly and save</p>',
            ]))
            ->set(self::TARGETS_PATH, [$this->subscriptionRow()])
            ->call('create')
            ->assertHasFormErrors(['custom_html']);

        $this->assertSame(0, AccountOffer::query()->count(), 'nothing is written when the block cannot be accepted');
        $this->assertSame(0, AccountOfferTarget::query()->count());
    }

    /**
     * A token naming a target that does not exist is a typo the merchant would
     * otherwise discover from a shopper who could not buy the thing.
     */
    public function test_custom_html_naming_an_unknown_target_is_refused(): void
    {
        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData([
                'custom_html' => '<p>{{button}} or {{button_nope}}</p>',
            ]))
            ->set(self::TARGETS_PATH, [$this->subscriptionRow(['token_key' => 'upgrade'])])
            ->call('create')
            ->assertHasFormErrors(['custom_html']);

        $this->assertSame(0, AccountOffer::query()->count());
    }

    /** Several buttons are the point of a multi-target offer, so several pass. */
    public function test_custom_html_may_carry_one_button_per_target(): void
    {
        $this->makeProduct(self::PRODUCT_MUG, 'Branded mug', 45.0);

        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData([
                'name' => 'Two buttons',
                'custom_html' => '<p>{{button_upgrade}}</p><p>{{button_mug}}</p>',
            ]))
            ->set(self::TARGETS_PATH, [
                $this->subscriptionRow(['token_key' => 'upgrade']),
                $this->oneTimeRow(['token_key' => 'mug']),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $offer = AccountOffer::query()->where('name', 'Two buttons')->firstOrFail();

        $this->assertNotNull($offer->targetByKey('upgrade'));
        $this->assertNotNull($offer->targetByKey('mug'));
        $this->assertNull($offer->targetByKey('nope'));
    }

    /**
     * The preview renders through AccountOfferPresenter — the SAME object the
     * live payload uses — so it cannot flatter the block. One button slot per
     * target, and the merchant's `<script>` nowhere near it.
     */
    public function test_the_preview_renders_a_button_slot_for_every_target(): void
    {
        $this->makeProduct(self::PRODUCT_MUG, 'Branded mug', 45.0);

        $offer = $this->makeOffer($this->template, [
            'token_key' => 'upgrade',
            'custom_html' => '<p>Only {{price}} — {{button_upgrade}} or {{button_mug}}</p>',
        ]);
        $this->addTarget($offer, [
            'kind' => AccountOfferTarget::KIND_ONE_TIME,
            'external_product_id' => self::PRODUCT_MUG,
            'token_key' => 'mug',
        ]);

        $html = Livewire::test(EditAccountOffer::class, ['record' => $offer->getKey()])
            ->assertOk()
            ->html();

        /*
         * Both slots are present in the sandboxed srcdoc, each carrying its OWN
         * target key — the block the shopper would receive, already reduced to
         * the inert sentinels the renderer swaps for controls it wired. The
         * quotes read `&quot;` because the whole document is htmlspecialchars'd
         * into the iframe attribute, which is the srcdoc contract itself.
         *
         * The raw `{{button_upgrade}}` is deliberately NOT asserted absent: the
         * merchant's source sits a few hundred bytes away in the code editor,
         * which is the point of showing them both at once.
         */
        $this->assertStringContainsString('data-target=&quot;upgrade&quot;', $html);
        $this->assertStringContainsString('data-target=&quot;mug&quot;', $html);
    }

    public function test_custom_html_is_stored_with_the_script_stripped(): void
    {
        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData([
                'custom_html' => '<p onclick="steal()">Only <strong>{{price}}</strong> {{button}}</p>'
                    .'<script>alert(document.cookie)</script>',
            ]))
            ->set(self::TARGETS_PATH, [$this->subscriptionRow()])
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

    /**
     * The CSS tab writes through the same discipline as the HTML one: SafeCss on
     * save (the hostile version never exists at rest) and again on read. The
     * `<`-stripping is what lets the preview concatenate this string inside
     * <style>…</style> without the string ever being able to close the tag.
     */
    public function test_custom_css_is_stored_scrubbed_and_read_back_clean(): void
    {
        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData([
                'custom_html' => '<div class="promo">{{button}}</div>',
                'custom_css' => '.promo { color: #c00; }'
                    .'@import url(https://evil.test/a.css);'
                    .'.promo::after { content: "</style><script>alert(1)</script>"; }',
            ]))
            ->set(self::TARGETS_PATH, [$this->subscriptionRow()])
            ->call('create')
            ->assertHasNoFormErrors();

        $offer = AccountOffer::query()->where('name', 'Switch to monthly')->firstOrFail();
        $stored = (string) $offer->custom_css;

        // Scrubbed AT REST, not merely on the way out.
        $this->assertStringNotContainsString('@import', $stored);
        $this->assertStringNotContainsString('evil.test', $stored);
        $this->assertStringNotContainsString('<', $stored);
        $this->assertStringContainsString('.promo { color: #c00; }', $stored, 'the merchant keeps their rules');

        // …and the guarded read agrees with what was written.
        $this->assertSame($stored, $offer->customCss());

        // A blanked-out stylesheet is a null, not an empty string the payload
        // would then ship as a truthy-looking value.
        $offer->custom_css = "   \n";
        $offer->save();
        $this->assertNull($offer->fresh()->custom_css);
    }

    /**
     * Every repeater row shows the merchant the exact `{{button_…}}` shortcode
     * its button answers to — resolved the way the renderer resolves it
     * (slug, else product id, else 1-based visual position), so what they copy
     * is what will charge.
     */
    public function test_each_target_row_shows_its_ready_to_copy_button_shortcode(): void
    {
        $this->makeProduct(self::PRODUCT_MUG, 'Branded mug', 45.0);

        Livewire::test(CreateAccountOffer::class)
            ->fillForm($this->formData())
            ->set(self::TARGETS_PATH, [
                'row-a' => $this->subscriptionRow(['token_key' => 'upgrade']),
                'row-b' => $this->subscriptionRow(),
                'row-c' => $this->oneTimeRow(),
            ])
            // The design system's monospace token chip, not ad-hoc styling.
            ->assertSeeHtml('rc-token')
            // The merchant's own slug wins…
            ->assertSeeHtml('{{button_upgrade}}')
            // …a nameless subscription row falls back to its VISUAL position…
            ->assertSeeHtml('{{button_2}}')
            // …and a nameless one-time row answers to its product id.
            ->assertSeeHtml('{{button_'.self::PRODUCT_MUG.'}}');
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
     * A complete, valid OFFER payload (everything but the targets, which the
     * repeater owns and each test sets explicitly). Individual tests override the
     * one field they are about, so a failure names the thing that broke.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function formData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Switch to monthly',
            'status' => AccountOffer::STATUS_ACTIVE,
            'placement' => AccountOffer::PLACEMENT_TOP,
            'priority' => 0,
            'shop_id' => $this->shopB->getKey(), // ignored: guarded + not in the schema
        ], $overrides);
    }

    /**
     * One subscription row of the repeater, as the browser would post it.
     *
     * Written as RAW repeater state rather than through fillForm(), because the
     * form mounts with one empty row already: fillForm() sets leaf paths, so a
     * numerically-keyed row would be added BESIDE the default one instead of
     * replacing it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function subscriptionRow(array $overrides = []): array
    {
        return array_merge([
            'kind' => AccountOfferTarget::KIND_SUBSCRIPTION,
            'product_subscription_plan_id' => $this->template->getKey(),
            'mode' => AccountOfferTarget::MODE_REPLACE,
            'replace_timing' => AccountOfferTarget::TIMING_PERIOD_END,
            'token_key' => null,
            'button_text' => null,
        ], $overrides);
    }

    /**
     * One one-time row: a mug, twice, riding the next order.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function oneTimeRow(array $overrides = []): array
    {
        return array_merge([
            'kind' => AccountOfferTarget::KIND_ONE_TIME,
            'external_product_id' => self::PRODUCT_MUG,
            'external_variant_id' => null,
            'quantity' => 2,
            'fulfilment' => AccountOfferTarget::FULFILMENT_NEXT_ORDER,
            'token_key' => null,
            'button_text' => null,
        ], $overrides);
    }

    /**
     * A nested lang array as dotted keys — what "key for key" actually compares.
     *
     * @param  array<string, mixed>  $items
     * @return list<string>
     */
    private function flatten(array $items, string $prefix = ''): array
    {
        $keys = [];

        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flatten($value, $path));

                continue;
            }

            $keys[] = $path;
        }

        return $keys;
    }
}
