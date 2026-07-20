<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowBranch;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Filament\Pages\FlowBuilder;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Flow-Builder "+ Add step" buttons on a branch that ENDS here: they append a fresh offer node
 * and route the accept (or decline) edge to it, so the merchant can build a multi-step funnel. The
 * decline "+" was entirely missing (no button, no method, no decline_is_end flag) — this pins both.
 */
final class FlowBuilderAddOfferTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create(['shopify_domain' => 'add-offer.myshopify.com', 'name' => 'AO', 'status' => Shop::STATUS_ACTIVE]);
        Tenant::set($this->shop);
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'ao@test.test', 'password' => bcrypt('password')]));
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_add_accept_offer_appends_a_node_and_routes_the_accept_edge(): void
    {
        $flow = $this->makeFlow();
        $source = $flow->offers()->first();

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('addAcceptOffer', $source->id)
            ->assertSet('drawerOpen', true); // the new node's config drawer opens immediately

        $this->assertSame(2, $flow->offers()->count());
        $branch = UpsellFlowBranch::where('from_offer_id', $source->id)->sole();
        $newOffer = $flow->offers()->where('id', '!=', $source->id)->sole();

        $this->assertSame($newOffer->id, $branch->on_accept_next_offer_id);
        $this->assertEmpty($branch->on_decline_next_offer_id);
    }

    public function test_add_decline_offer_appends_a_node_and_routes_the_decline_edge(): void
    {
        $flow = $this->makeFlow();
        $source = $flow->offers()->first();

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('addDeclineOffer', $source->id)
            ->assertSet('drawerOpen', true);

        $branch = UpsellFlowBranch::where('from_offer_id', $source->id)->sole();
        $newOffer = $flow->offers()->where('id', '!=', $source->id)->sole();

        $this->assertSame($newOffer->id, $branch->on_decline_next_offer_id);
        $this->assertEmpty($branch->on_accept_next_offer_id); // accept path untouched
    }

    public function test_both_branch_end_flags_are_exposed_to_the_view(): void
    {
        $flow = $this->makeFlow();

        $offers = Livewire::test(FlowBuilder::class, ['flow' => $flow->id])->instance()->offers;

        $this->assertTrue($offers[0]['accept_is_end']);
        $this->assertTrue($offers[0]['decline_is_end']);
    }

    public function test_adding_on_a_branch_that_already_continues_is_a_noop(): void
    {
        $flow = $this->makeFlow();
        $source = $flow->offers()->first();

        // First add creates + routes the decline edge; a second call must NOT orphan it.
        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id]);
        $component->call('addDeclineOffer', $source->id);
        $routedTo = UpsellFlowBranch::where('from_offer_id', $source->id)->sole()->on_decline_next_offer_id;

        $component->call('addDeclineOffer', $source->id);

        $this->assertSame(2, $flow->offers()->count(), 'No second node — the decline edge already continues.');
        $this->assertSame($routedTo, UpsellFlowBranch::where('from_offer_id', $source->id)->sole()->on_decline_next_offer_id);
    }

    public function test_save_layout_persists_only_valid_keys_clamped(): void
    {
        $flow = $this->makeFlow();
        $offerId = $flow->offers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('saveLayout', [
                'trigger' => ['x' => 10, 'y' => 20],
                'offer:'.$offerId => ['x' => 500, 'y' => 999999], // y clamped to LAYOUT_MAX
                'offer:424242' => ['x' => 1, 'y' => 1],           // foreign offer key → dropped
                'evil' => ['x' => 1, 'y' => 1],                   // unknown key → dropped
            ]);

        $layout = $flow->fresh()->layout;
        $this->assertArrayHasKey('trigger', $layout);
        $this->assertArrayHasKey('offer:'.$offerId, $layout);
        $this->assertArrayNotHasKey('offer:424242', $layout, 'a foreign offer key must be dropped');
        $this->assertArrayNotHasKey('evil', $layout, 'an unknown key must be dropped');
        $this->assertEqualsWithDelta(10.0, (float) $layout['trigger']['x'], 0.01);
        $this->assertEqualsWithDelta((float) FlowBuilder::LAYOUT_MAX, (float) $layout['offer:'.$offerId]['y'], 0.01);
    }

    /** A draft flow with one any_product trigger + one seeded offer (tenant-bound). */
    private function makeFlow(): UpsellFlow
    {
        $flow = new UpsellFlow(['name' => 'Add flow', 'priority' => 1]);
        $flow->shop_id = $this->shop->id;
        $flow->forceFill(['status' => UpsellFlowStatus::DRAFT->value])->save();

        UpsellFlowTrigger::create(['flow_id' => $flow->id, 'match_type' => UpsellFlowTrigger::MATCH_ANY_PRODUCT]);
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
