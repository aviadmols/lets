<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Filament\Pages\FlowBuilder;
use App\Filament\Pages\PostPurchaseOffers;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Shopify-Flow-style canvas (drag + live connectors). Proves the backend contract the
 * Alpine board relies on: nodeLayout() defaults + persists, saveLayout() sanitises the
 * client payload (foreign node keys dropped, coordinates clamped, tenant-scoped), edges()
 * wires the trigger → first offer and each branch → its next offer, and the inline rename
 * writes the flow name (blank → untitled). Geometry itself lives in flow-builder.js.
 */
final class FlowBuilderCanvasTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop('canvas-demo.myshopify.com');
        Tenant::set($this->shop);

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'canvas@test.test',
            'password' => bcrypt('password'),
        ]));
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_node_layout_defaults_then_reflects_saved_positions(): void
    {
        $flow = $this->makeFlow();
        $offerId = $flow->offers()->first()->id;

        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id]);

        // Fresh flow → auto-layout defaults for the trigger + the one offer.
        $layout = $component->instance()->nodeLayout();
        $this->assertArrayHasKey('trigger', $layout);
        $this->assertArrayHasKey('offer:' . $offerId, $layout);
        $this->assertSame((float) FlowBuilder::LAYOUT_TRIGGER_X, $layout['trigger']['x']);

        // Drag persists → nodeLayout echoes the stored coordinates.
        $component->call('saveLayout', [
            'trigger' => ['x' => 120, 'y' => 260],
            'offer:' . $offerId => ['x' => 640, 'y' => 300],
        ]);

        $this->assertEquals(
            ['x' => 120.0, 'y' => 260.0],
            $flow->fresh()->layout['trigger'],
        );
        $this->assertEquals(
            ['x' => 640.0, 'y' => 300.0],
            $component->instance()->nodeLayout()['offer:' . $offerId],
        );
    }

    public function test_save_layout_drops_foreign_keys_and_clamps_coordinates(): void
    {
        $flow = $this->makeFlow();
        $offerId = $flow->offers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('saveLayout', [
                'trigger' => ['x' => 999999, 'y' => -999999],  // out of range → clamped
                'offer:' . $offerId => ['x' => 10, 'y' => 20],
                'offer:99999' => ['x' => 5, 'y' => 5],          // not a node of this flow → dropped
                'evil' => ['x' => 1, 'y' => 1],                 // junk key → dropped
            ]);

        $stored = $flow->fresh()->layout;

        $this->assertSame(['trigger', 'offer:' . $offerId], array_keys($stored));
        $this->assertEquals(FlowBuilder::LAYOUT_MAX, $stored['trigger']['x']);
        $this->assertEquals(FlowBuilder::LAYOUT_MIN, $stored['trigger']['y']);
    }

    public function test_a_foreign_flow_never_loads_so_its_layout_is_never_editable(): void
    {
        // A rival shop's flow must never open in our session — it redirects to the hub instead of
        // exposing (or letting us drag/persist) another shop's graph. This is the tenant seam that
        // makes saveLayout() safe: it only ever resolves OUR flow via the global scope.
        $other = $this->makeShop('rival.myshopify.com');
        $foreignFlowId = Tenant::run($other, fn () => $this->makeFlow()->id);

        Livewire::test(FlowBuilder::class, ['flow' => $foreignFlowId])
            ->assertRedirect(PostPurchaseOffers::getUrl());

        $this->assertNull(
            Tenant::run($other, fn () => UpsellFlow::findOrFail($foreignFlowId)->layout),
        );
    }

    public function test_edges_wire_trigger_to_first_offer_and_the_accept_chain(): void
    {
        $flow = $this->makeFlow();
        $firstOfferId = $flow->offers()->first()->id;

        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id]);

        // One offer → a single trigger → offer edge.
        $edges = $component->instance()->edges();
        $this->assertCount(1, $edges);
        $this->assertSame(['from' => 'trigger', 'to' => 'offer:' . $firstOfferId, 'kind' => 'trigger'], $edges[0]);

        // Append a step on accept → a new accept edge from the first offer to it.
        $component->call('addAcceptOffer', $firstOfferId);
        $secondOfferId = $flow->offers()->orderBy('id', 'desc')->first()->id;

        $edges = $component->instance()->edges();
        $this->assertContains(
            ['from' => 'offer:' . $firstOfferId, 'to' => 'offer:' . $secondOfferId, 'kind' => 'accept'],
            $edges,
        );
    }

    public function test_edge_d_is_rendered_server_side_so_arrows_show_without_js(): void
    {
        $flow = $this->makeFlow();
        $firstOfferId = $flow->offers()->first()->id;

        $instance = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])->instance();
        $layout = $instance->nodeLayout();

        // A real edge yields a concrete cubic-bezier path (visible immediately, no Alpine needed).
        $d = $instance->edgeD($layout, 'trigger', 'offer:' . $firstOfferId);
        $this->assertMatchesRegularExpression('/^M [\d.-]+,[\d.-]+ C /', $d);

        // A missing node → empty path (no stray line).
        $this->assertSame('', $instance->edgeD($layout, 'trigger', 'offer:99999'));
    }

    public function test_rename_flow_writes_the_name_and_blank_falls_back_to_untitled(): void
    {
        $flow = $this->makeFlow();

        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->set('flowName', 'Holiday upsell')
            ->call('renameFlow');

        $this->assertSame('Holiday upsell', $flow->fresh()->name);

        $component->set('flowName', '   ')->call('renameFlow');
        $this->assertSame(__('upsell.admin.builder.untitled'), $flow->fresh()->name);
    }

    // === helpers (mirror FlowBuilderProductPickerTest) ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
        ]);
    }

    /** A draft flow with one any_product trigger + one seeded offer (tenant-bound). */
    private function makeFlow(): UpsellFlow
    {
        $flow = new UpsellFlow(['name' => 'Canvas flow', 'priority' => 1]);
        $flow->shop_id = Tenant::id();
        $flow->forceFill(['status' => UpsellFlowStatus::DRAFT->value])->save();

        UpsellFlowTrigger::create([
            'flow_id' => $flow->id,
            'match_type' => UpsellFlowTrigger::MATCH_ANY_PRODUCT,
        ]);
        UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/1',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/10',
            'offer_title' => 'Seeded offer',
            'base_price' => 20.0,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'headline' => 'Add this',
            'accept_cta' => 'Add to my order',
            'position' => 0,
        ]);

        return $flow->fresh();
    }
}
