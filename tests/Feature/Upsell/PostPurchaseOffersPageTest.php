<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Domain\Upsell\Models\UpsellSetting;
use App\Filament\Pages\FlowBuilder;
use App\Filament\Pages\PostPurchaseOffers;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke tests for the Post-Purchase Offers admin UI (Phase 6). Proves the hub
 * page + all 4 tabs render, the "Your flows" table lists tenant-scoped flows,
 * the KPI funnel computes off seeded events, the Settings tab persists, and the
 * Flow Builder renders a flow's graph + guards activation. Renders only — the
 * money/funnel truth is laravel-backend's (UpsellMetrics is its contract).
 */
final class PostPurchaseOffersPageTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'pp-demo.myshopify.com',
            'name' => 'PP Demo',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'pp@test.test',
            'password' => bcrypt('password'),
        ]));
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_hub_renders_all_four_tabs_and_lists_active_flows(): void
    {
        $this->makeFlow('Active flow A', UpsellFlowStatus::ACTIVE->value, 1);
        $this->makeFlow('Active flow B', UpsellFlowStatus::ACTIVE->value, 2);
        $this->makeFlow('Draft flow', UpsellFlowStatus::DRAFT->value, 3);

        Livewire::test(PostPurchaseOffers::class)
            ->assertOk()
            // 4 tabs present
            ->assertSee(__('upsell.admin.tab.overview'))
            ->assertSee(__('upsell.admin.tab.performance'))
            ->assertSee(__('upsell.admin.tab.activity'))
            ->assertSee(__('upsell.admin.tab.settings'))
            // NEW badge + Create-new button + Your flows
            ->assertSee(__('upsell.admin.badge_new'))
            ->assertSee(__('upsell.admin.flows.create'))
            ->assertSee(__('upsell.admin.flows.title'))
            // Active flows listed by priority (draft excluded from the active sub-tab)
            ->assertSee('Active flow A')
            ->assertSee('Active flow B')
            ->assertDontSee('Draft flow');
    }

    public function test_inactive_subtab_shows_drafts(): void
    {
        $this->makeFlow('Active flow', UpsellFlowStatus::ACTIVE->value, 1);
        $this->makeFlow('Draft flow', UpsellFlowStatus::DRAFT->value, 2);

        Livewire::test(PostPurchaseOffers::class)
            ->call('setFlowScope', 'inactive')
            ->assertSee('Draft flow')
            ->assertDontSee('Active flow');
    }

    public function test_overview_kpis_reflect_seeded_funnel(): void
    {
        $flow = $this->makeFlow('Funnel flow', UpsellFlowStatus::ACTIVE->value, 1);
        $offerId = $flow->offers()->first()->id;

        UpsellOfferEvent::create(['shop_id' => $this->shop->id, 'flow_id' => $flow->id, 'offer_id' => $offerId, 'event_type' => 'impression', 'occurred_at' => now()->subDays(2), 'created_at' => now()]);
        UpsellOfferEvent::create(['shop_id' => $this->shop->id, 'flow_id' => $flow->id, 'offer_id' => $offerId, 'event_type' => 'accepted', 'occurred_at' => now()->subDays(2), 'created_at' => now()]);
        UpsellOfferEvent::create(['shop_id' => $this->shop->id, 'flow_id' => $flow->id, 'offer_id' => $offerId, 'event_type' => 'charge_succeeded', 'revenue_amount' => 120.0, 'occurred_at' => now()->subDays(2), 'created_at' => now()]);

        $kpis = (new PostPurchaseOffers())->overviewKpis();

        $this->assertSame('1', $kpis['impressions']['value']);
        $this->assertStringContainsString('100', $kpis['conversion']['value']); // 1 accepted / 1 impression
        $this->assertSame('1', $kpis['orders']['value']);
    }

    public function test_settings_tab_persists_partial_paid_handling(): void
    {
        Livewire::test(PostPurchaseOffers::class)
            ->set('tab', 'settings')
            ->set('partialPaidHandling', UpsellSetting::PARTIAL_DO_NOTHING)
            ->set('removalWindow', 48)
            ->call('saveSettings');

        $settings = UpsellSetting::current();
        $this->assertSame(UpsellSetting::PARTIAL_DO_NOTHING, $settings->partial_paid_handling);
    }

    public function test_flow_builder_renders_graph_and_blocks_invalid_activation(): void
    {
        // A flow with a trigger + a complete offer → activatable.
        $valid = $this->makeFlow('Valid flow', UpsellFlowStatus::DRAFT->value, 1);

        Livewire::test(FlowBuilder::class, ['flow' => $valid->id])
            ->assertOk()
            ->assertSee(__('upsell.admin.builder.node.trigger'))
            ->assertSee(__('upsell.admin.builder.node.offer'))
            ->assertSee('Test offer'); // offer title from makeFlow
    }

    public function test_flow_builder_flags_an_offer_missing_copy(): void
    {
        $flow = new UpsellFlow(['name' => 'Incomplete', 'priority' => 1]);
        $flow->shop_id = $this->shop->id;
        $flow->forceFill(['status' => UpsellFlowStatus::DRAFT->value])->save();

        UpsellFlowTrigger::create(['flow_id' => $flow->id, 'match_type' => UpsellFlowTrigger::MATCH_ANY_PRODUCT]);
        // Offer with NO headline → invalid node.
        UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/9',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/90',
            'offer_title' => 'No copy offer',
            'base_price' => 30.0,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'accept_cta' => 'Add',
            'position' => 0,
        ]);

        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id]);
        $this->assertNotEmpty($component->instance()->validationIssues());
        $this->assertFalse($component->instance()->isActivatable());
    }

    public function test_offer_node_opens_configure_drawer_with_its_sections(): void
    {
        $flow = $this->makeFlow('Config flow', UpsellFlowStatus::DRAFT->value, 1);
        $offerId = $flow->offers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            // Node renders as the Recharge "Cross-sell" card.
            ->assertSee(__('upsell.admin.configure.crosssell'))
            ->assertSee(__('upsell.admin.configure.single_product'))
            // Clicking the node opens the drawer with its section headings.
            ->call('openOfferConfig', $offerId)
            ->assertSet('drawerOpen', true)
            ->assertSet('configOfferId', $offerId)
            ->assertSee(__('upsell.admin.configure.title'))
            ->assertSee(__('upsell.admin.configure.what_product'))
            ->assertSee(__('upsell.admin.configure.how_variants'))
            ->assertSee(__('upsell.admin.configure.purchase_options'))
            ->assertSee(__('upsell.admin.configure.subscription_warning'))
            ->assertSee(__('upsell.admin.configure.discount_label'))
            ->assertSee(__('upsell.admin.configure.shipping_label'))
            ->assertSee(__('upsell.admin.configure.display_options'))
            ->assertSee(__('upsell.admin.configure.view_post_purchase'));
    }

    public function test_configure_drawer_persists_new_offer_config_columns(): void
    {
        $flow = $this->makeFlow('Persist flow', UpsellFlowStatus::DRAFT->value, 1);
        $offerId = $flow->offers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->set('productSelectionMode', UpsellFlowOffer::PRODUCT_SMART)
            ->set('variantSelectionMode', UpsellFlowOffer::VARIANT_MERCHANT)
            ->set('purchaseOption', UpsellFlowOffer::PURCHASE_SUBSCRIPTION_ONLY)
            ->set('discountPercent', 15)
            ->set('applyDiscountOnTop', true)
            ->set('shippingFeeMode', UpsellFlowOffer::SHIPPING_CHARGE)
            ->set('showTimer', false)
            ->call('saveOfferConfig')
            ->assertSet('drawerOpen', false);

        $offer = UpsellFlowOffer::findOrFail($offerId);

        $this->assertSame(UpsellFlowOffer::PRODUCT_SMART, $offer->product_selection_mode);
        $this->assertSame(UpsellFlowOffer::VARIANT_MERCHANT, $offer->variant_selection_mode);
        $this->assertSame(UpsellFlowOffer::PURCHASE_SUBSCRIPTION_ONLY, $offer->purchase_option);
        $this->assertTrue($offer->apply_discount_on_top);
        $this->assertSame(UpsellFlowOffer::SHIPPING_CHARGE, $offer->shipping_fee_mode);
        $this->assertFalse($offer->show_timer);
        // The "%" input maps to discount_type=percent + discount_value (money truth).
        $this->assertSame(UpsellFlowOffer::DISCOUNT_PERCENT, $offer->discount_type);
        $this->assertSame(15, (int) round((float) $offer->discount_value));
        // Zero percent clears the discount back to none.
        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->set('discountPercent', 0)
            ->call('saveOfferConfig');
        $this->assertSame(UpsellFlowOffer::DISCOUNT_NONE, UpsellFlowOffer::findOrFail($offerId)->discount_type);
    }

    public function test_create_new_builds_a_draft_flow_with_default_trigger_and_offer_and_redirects(): void
    {
        $this->assertSame(0, UpsellFlow::count());

        Livewire::test(PostPurchaseOffers::class)
            ->call('createFlow')
            ->assertRedirect(); // → the real new flow builder, never flow/0

        // Exactly one draft flow was created for the bound shop, seeded 1:1.
        $flow = UpsellFlow::query()->firstOrFail();
        $this->assertSame($this->shop->id, $flow->shop_id);
        $this->assertSame(UpsellFlowStatus::DRAFT, $flow->status);
        $this->assertSame(1, $flow->priority); // first flow → priority 1
        $this->assertSame(1, $flow->triggers()->count());
        $this->assertSame(1, $flow->offers()->count());

        $trigger = $flow->triggers()->first();
        $this->assertSame(UpsellFlowTrigger::MATCH_ANY_PRODUCT, $trigger->match_type);

        $offer = $flow->offers()->first();
        $this->assertSame(0, $offer->position);

        // The redirect targets THIS flow's builder URL (a real, openable page).
        Livewire::test(PostPurchaseOffers::class)
            ->call('createFlow')
            ->assertRedirect(FlowBuilder::getUrl(['flow' => UpsellFlow::query()->latest('id')->first()->id]));

        // Priority increments off the existing max (no collisions).
        $this->assertSame(2, UpsellFlow::query()->latest('id')->first()->priority);
    }

    public function test_missing_flow_redirects_to_hub_instead_of_404(): void
    {
        // A flow id that does not exist for this shop bounces back to the hub.
        Livewire::test(FlowBuilder::class, ['flow' => 999999])
            ->assertRedirect(PostPurchaseOffers::getUrl());
    }

    public function test_trigger_node_opens_drawer_and_save_persists_match_type_and_subfield(): void
    {
        $flow = $this->makeFlow('Trigger flow', UpsellFlowStatus::DRAFT->value, 1);
        $triggerId = $flow->triggers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            // Clicking the green Trigger node opens the "Configure trigger" drawer.
            ->call('openTriggerConfig')
            ->assertSet('triggerDrawerOpen', true)
            ->assertSet('configTriggerId', $triggerId)
            ->assertSet('triggerMatchType', UpsellFlowTrigger::MATCH_ANY_PRODUCT)
            ->assertSee(__('upsell.admin.trigger_config.title'))
            ->assertSee(__('upsell.admin.trigger_config.which_label'))
            // Choose "Order value over an amount" + its sub-field, then save.
            ->set('triggerMatchType', UpsellFlowTrigger::MATCH_MIN_ORDER_VALUE)
            ->set('triggerMinOrderValue', '150')
            ->set('triggerTag', 'should-be-nulled') // a stale sub-field…
            ->call('saveTriggerConfig')
            ->assertSet('triggerDrawerOpen', false);

        $trigger = UpsellFlowTrigger::findOrFail($triggerId);
        $this->assertSame(UpsellFlowTrigger::MATCH_MIN_ORDER_VALUE, $trigger->match_type);
        $this->assertSame(150.0, (float) $trigger->min_order_value);
        // Only the relevant sub-field is written; the others are nulled.
        $this->assertNull($trigger->tag);
        $this->assertNull($trigger->shopify_product_gid);
        $this->assertNull($trigger->shopify_collection_gid);
    }

    public function test_save_trigger_config_is_a_noop_for_a_foreign_flow(): void
    {
        // A second shop owns a flow + trigger.
        $other = Shop::create([
            'shopify_domain' => 'other.myshopify.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
        ]);

        $foreignTriggerId = Tenant::run($other, function () use ($other): int {
            $flow = new UpsellFlow(['name' => 'Foreign flow', 'priority' => 1]);
            $flow->shop_id = $other->id;
            $flow->forceFill(['status' => UpsellFlowStatus::DRAFT->value])->save();

            return UpsellFlowTrigger::create([
                'flow_id' => $flow->id,
                'match_type' => UpsellFlowTrigger::MATCH_ANY_PRODUCT,
            ])->id;
        });

        // Bound to the test shop: this shop owns its own flow.
        $ourFlow = $this->makeFlow('Our flow', UpsellFlowStatus::DRAFT->value, 1);

        // Forcing the configTriggerId to the foreign trigger must NOT persist:
        // the tenant-scoped lookup resolves to null, so save is a no-op.
        Livewire::test(FlowBuilder::class, ['flow' => $ourFlow->id])
            ->set('configTriggerId', $foreignTriggerId)
            ->set('triggerMatchType', UpsellFlowTrigger::MATCH_TAG)
            ->set('triggerTag', 'hacked')
            ->call('saveTriggerConfig');

        // The foreign trigger is untouched (still any_product, no tag).
        $foreign = Tenant::run($other, fn () => UpsellFlowTrigger::findOrFail($foreignTriggerId));
        $this->assertSame(UpsellFlowTrigger::MATCH_ANY_PRODUCT, $foreign->match_type);
        $this->assertNull($foreign->tag);
    }

    private function makeFlow(string $name, string $status, int $priority): UpsellFlow
    {
        $flow = new UpsellFlow(['name' => $name, 'priority' => $priority]);
        $flow->shop_id = $this->shop->id;
        $flow->forceFill(['status' => $status])->save();

        UpsellFlowTrigger::create([
            'flow_id' => $flow->id,
            'match_type' => UpsellFlowTrigger::MATCH_ANY_PRODUCT,
        ]);
        UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/5',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/50',
            'offer_title' => 'Test offer',
            'base_price' => 50.0,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'headline' => 'Add this',
            'accept_cta' => 'Add to my order',
            'position' => 0,
        ]);

        return $flow->fresh();
    }
}
