<?php

namespace Tests\Feature\Upsell;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Filament-authed, tenant-scoped "View post-purchase" preview route (Phase 3). It renders the
 * REAL shared card for the merchant's own offer; it 404s a foreign offer via the global scope (NO
 * withoutGlobalScopes, unlike the deleted dev route); and it is money-safe — a preview writes NO
 * ledger row and NO funnel event, and the price is the server-computed discountedPrice.
 */
final class UpsellPreviewRouteTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create([
            'shopify_domain' => 'prev.myshopify.com',
            'name' => 'Prev',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        $this->merchant = User::factory()->forShop($this->shop)->create();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_merchant_previews_own_offer_with_server_price_and_no_side_effects(): void
    {
        $offer = Tenant::run($this->shop, fn () => $this->offer(100.0, UpsellFlowOffer::DISCOUNT_PERCENT, 25));

        $url = route('filament.admin.upsell.preview', ['platform' => 'woocommerce', 'offer' => $offer->id]);

        $this->actingAs($this->merchant)->get($url)
            ->assertOk()
            ->assertSee('upsell/lets-ppu.js')          // the shared renderer is loaded
            ->assertSee('75.00');                        // server-computed discountedPrice (100 - 25%)

        // Money law: a preview never charges and never records a funnel event.
        $this->assertSame(0, PaymentLedger::withoutGlobalScopes()->count());
        $this->assertSame(0, UpsellOfferEvent::withoutGlobalScopes()->count());
    }

    public function test_a_foreign_offer_is_404_not_leaked(): void
    {
        $other = Shop::create(['shopify_domain' => 'other-prev.myshopify.com', 'name' => 'O', 'status' => Shop::STATUS_ACTIVE]);
        $foreignOffer = Tenant::run($other, fn () => $this->offer(50.0));

        $url = route('filament.admin.upsell.preview', ['platform' => 'woocommerce', 'offer' => $foreignOffer->id]);

        $this->actingAs($this->merchant)->get($url)->assertNotFound();
    }

    public function test_offer_zero_renders_the_sample_card(): void
    {
        $url = route('filament.admin.upsell.preview', ['platform' => 'woocommerce', 'offer' => 0]);

        $this->actingAs($this->merchant)->get($url)
            ->assertOk()
            ->assertSee(__('upsell.preview.sample_product'));
    }

    public function test_shopify_platform_is_rejected_until_it_lands(): void
    {
        // The route constraint only allows `woocommerce` for now.
        $this->actingAs($this->merchant)->get('/admin/upsell/preview/shopify/1')->assertNotFound();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $url = route('filament.admin.upsell.preview', ['platform' => 'woocommerce', 'offer' => 0]);

        $this->get($url)->assertRedirect();
    }

    private function offer(float $base, string $discountType = UpsellFlowOffer::DISCOUNT_NONE, float $discountValue = 0): UpsellFlowOffer
    {
        $flow = new UpsellFlow(['name' => 'F', 'priority' => 1]);
        $flow->shop_id = Tenant::id();
        $flow->forceFill(['status' => UpsellFlowStatus::ACTIVE->value])->save();

        return UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => 'gid://shopify/Product/1',
            'offer_variant_gid' => 'gid://shopify/ProductVariant/10',
            'offer_title' => 'Preview offer',
            'headline' => 'Add this',
            'accept_cta' => 'Add to my order',
            'base_price' => $base,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'position' => 0,
        ]);
    }
}
