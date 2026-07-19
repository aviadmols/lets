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
 * The node-corner "delete offer" control. The load-bearing behaviour: deleting an offer NULLS every
 * OTHER branch that TARGETS it (those columns have no FK cascade), so those paths revert to "End flow"
 * and regain "+ Add step" instead of dangling; it also prunes the offer's stored layout key. A
 * foreign/missing id is a silent no-op; deleting the drawer-open offer closes the drawer.
 */
final class FlowBuilderDeleteOfferTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create(['shopify_domain' => 'del-offer.myshopify.com', 'name' => 'DO', 'status' => Shop::STATUS_ACTIVE]);
        Tenant::set($this->shop);
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'do@test.test', 'password' => bcrypt('password')]));
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_delete_offer_removes_the_node_and_nulls_the_incoming_branch(): void
    {
        $flow = $this->makeFlow();
        $source = $flow->offers()->first();

        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id]);
        $component->call('addAcceptOffer', $source->id); // creates offer B, routes source.accept → B
        $newOffer = $flow->offers()->where('id', '!=', $source->id)->sole();

        // Sanity: the accept edge exists before the delete.
        $this->assertContains(
            ['from' => 'offer:' . $source->id, 'to' => 'offer:' . $newOffer->id, 'kind' => 'accept'],
            $component->instance()->edges(),
        );

        $component->call('deleteOffer', $newOffer->id);

        // The node is gone, the source's accept pointer is nulled, and its "+ Add step" is back.
        $this->assertSame(1, $flow->offers()->count());
        $branch = UpsellFlowBranch::where('from_offer_id', $source->id)->first();
        $this->assertNull($branch?->on_accept_next_offer_id);
        $offers = $component->instance()->offers;
        $this->assertTrue($offers[0]['accept_is_end']);
        // And the dangling edge is dropped.
        $this->assertSame([['from' => 'trigger', 'to' => 'offer:' . $source->id, 'kind' => 'trigger']], $component->instance()->edges());
    }

    public function test_delete_offer_prunes_its_layout_key(): void
    {
        $flow = $this->makeFlow();
        $source = $flow->offers()->first();

        $component = Livewire::test(FlowBuilder::class, ['flow' => $flow->id]);
        $component->call('addAcceptOffer', $source->id);
        $newOffer = $flow->offers()->where('id', '!=', $source->id)->sole();

        $component->call('saveLayout', [
            'trigger' => ['x' => 40, 'y' => 200],
            'offer:' . $source->id => ['x' => 360, 'y' => 140],
            'offer:' . $newOffer->id => ['x' => 700, 'y' => 140],
        ]);

        $component->call('deleteOffer', $newOffer->id);

        $this->assertSame(['trigger', 'offer:' . $source->id], array_keys($flow->fresh()->layout));
    }

    public function test_deleting_a_missing_or_foreign_offer_is_a_noop(): void
    {
        // A rival shop's offer id must never be deletable through our bound session.
        $other = Shop::create(['shopify_domain' => 'rival.myshopify.com', 'name' => 'R', 'status' => Shop::STATUS_ACTIVE]);
        $foreignOfferId = Tenant::run($other, fn () => $this->makeFlow()->offers()->first()->id);

        $flow = $this->makeFlow();

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('deleteOffer', $foreignOfferId)   // foreign → no-op
            ->call('deleteOffer', 999999);           // missing → no-op

        $this->assertSame(1, $flow->offers()->count());
        $this->assertTrue(
            Tenant::run($other, fn () => UpsellFlowOffer::whereKey($foreignOfferId)->exists()),
            'The rival shop\'s offer must be untouched.',
        );
    }

    public function test_deleting_the_drawer_open_offer_closes_the_drawer(): void
    {
        $flow = $this->makeFlow();
        $offerId = $flow->offers()->first()->id;

        Livewire::test(FlowBuilder::class, ['flow' => $flow->id])
            ->call('openOfferConfig', $offerId)
            ->assertSet('drawerOpen', true)
            ->call('deleteOffer', $offerId)
            ->assertSet('drawerOpen', false)
            ->assertSet('configOfferId', 0);

        $this->assertSame(0, $flow->offers()->count());
    }

    /** A draft flow with one any_product trigger + one seeded offer (tenant-bound). */
    private function makeFlow(): UpsellFlow
    {
        $flow = new UpsellFlow(['name' => 'Del flow', 'priority' => 1]);
        $flow->shop_id = Tenant::id();
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
