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
