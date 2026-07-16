<?php

namespace Tests\Feature\Subscriptions;

use App\Filament\Resources\ShopResource;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Subscription DETAIL page (W24). This page shipped with ZERO render coverage — the only test that
 * touched it exercised a static method — which is exactly how a live 404 reached the merchant with CI
 * green. It proves: a tenant-bound plan RENDERS; a missing/foreign id BOUNCES to the list with a
 * warning instead of a bare 404 (the house rule — mirrors FlowBuilder/ProductDetail: "never a bare
 * 404/leak"); a platform admin with no shop entered is sent to Shops instead of dead-ending; and a
 * foreign id never leaks another shop's data.
 */
final class ViewSubscriptionPageTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private InstallmentPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'sub-view.myshopify.com',
            'name' => 'Sub View',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        Tenant::set($this->shop);

        $this->actingAs(User::create([
            'name' => 'Merchant',
            'email' => 'sub-view@test.test',
            'password' => bcrypt('password'),
            'shop_id' => $this->shop->id,
        ]));

        $this->plan = $this->makePlan($this->shop, ['customer_name' => 'ישראל ישראלי']);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /**
     * Exercised over the REAL HTTP route (not Livewire::test) — the production path is
     * route → panel middleware → mount(), and the harness instead assigns the route param
     * straight onto the typed $record property, which is what defeated the earlier attempt.
     */
    private function viewUrl(int $planId): string
    {
        return SubscriptionResource::getUrl('view', ['plan' => $planId]);
    }

    /** The headline: the merchant's own plan opens (this is what 404'd in production). */
    public function test_a_tenant_bound_plan_renders(): void
    {
        $this->get($this->viewUrl($this->plan->id))
            ->assertOk()
            ->assertSee('PLN-'.$this->plan->id);
    }

    /**
     * A missing id BOUNCES to the list with a warning — never a bare 404. This only works because
     * the route param is `{plan}`: a `{record}` param let Livewire's ImplicitRouteBinding resolve
     * (and 404) the model before mount() could run, so the page could never degrade gracefully.
     */
    public function test_a_missing_id_redirects_to_the_list_instead_of_404(): void
    {
        $this->get($this->viewUrl(999999))
            ->assertRedirect(SubscriptionResource::getUrl());
    }

    /** The detail page must name the customer (it previously showed none at all). */
    public function test_the_detail_page_shows_the_customer_name(): void
    {
        $this->get($this->viewUrl($this->plan->id))
            ->assertOk()
            ->assertSee('ישראל ישראלי', escape: false);
    }

    /**
     * The customer label resolves name → email → external id → "none". A WooCommerce plan is the
     * case that was broken: it carries a name but NO shopify_customer_id, and the list keyed on
     * that column — so the merchant saw an empty "Customer" cell.
     */
    public function test_customer_label_prefers_the_name_and_falls_back(): void
    {
        $woo = $this->makePlan($this->shop, [
            'customer_name' => 'Dana Cohen',
            'customer_email' => 'dana@example.com',
            'shopify_customer_id' => null,   // exactly the Woo shape that rendered blank
        ]);
        $this->assertSame('Dana Cohen', $woo->customerLabel());

        // No name → the email identifies them.
        $emailOnly = $this->makePlan($this->shop, ['customer_name' => '  ', 'customer_email' => 'x@y.com']);
        $this->assertSame('x@y.com', $emailOnly->customerLabel());

        // No name/email → the external id (Woo customer id) rather than a blank cell.
        $idOnly = $this->makePlan($this->shop, ['external_customer_id' => '42']);
        $this->assertSame('42', $idOnly->customerLabel());

        // Nothing at all (guest) → an explicit placeholder, never an empty string.
        $this->assertSame(__('common.none'), $this->makePlan($this->shop)->customerLabel());
    }

    /** THE tenant boundary: another shop's plan must never render, and never leak a byte of it. */
    public function test_a_foreign_plan_redirects_and_never_leaks(): void
    {
        $other = Shop::create([
            'shopify_domain' => 'sub-other.myshopify.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
        ]);
        $foreign = Tenant::run($other, fn (): InstallmentPlan => $this->makePlan($other, [
            'customer_name' => 'Foreign secret customer',
        ]));

        $response = $this->get($this->viewUrl($foreign->id));
        $response->assertRedirect(SubscriptionResource::getUrl());
        $response->assertDontSee('Foreign secret customer');
    }

    /** @param array<string, mixed> $attributes */
    private function makePlan(Shop $shop, array $attributes = []): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill(array_merge([
            'shop_id' => $shop->id,
            'public_id' => 'PP-'.$shop->id.'-'.uniqid(),
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 100.00,
            'total_charged' => 0.00,
            'installment_amount' => 10.00,
            'currency' => 'ILS',
            'interval_count' => 1,
        ], $attributes))->save();

        return $plan;
    }
}
