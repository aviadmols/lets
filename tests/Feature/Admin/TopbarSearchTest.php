<?php

namespace Tests\Feature\Admin;

use App\Filament\Search\TenantGlobalSearchProvider;
use App\Livewire\TopbarSearch;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The top bar's search (TenantGlobalSearchProvider): it finds the BOUND shop's
 * customers, subscriptions and orders, never another shop's, and answers with
 * nothing at all when no shop is bound.
 */
final class TopbarSearchTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SHARED_NAME = 'Dana Levi';

    /** Three groups, each one query plus at most one fallback, plus product eager-loading. */
    private const MAX_QUERIES = 7;

    private Shop $mine;

    private Shop $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = $this->shop('mine.example.com');
        $this->theirs = $this->shop('theirs.example.com');

        // The same name in BOTH shops, so a leak would be visible.
        $this->plan($this->mine, self::SHARED_NAME, 'dana@mine.test', '0501111111', ref: '11', order: '5001');
        $this->plan($this->theirs, self::SHARED_NAME, 'dana@theirs.test', '0502222222', ref: '22', order: '5002');

        Tenant::set($this->mine);
        $this->actingAs(User::factory()->forShop($this->mine)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_it_finds_the_bound_shops_customer_and_subscription(): void
    {
        $results = $this->search('dana');

        $customers = $this->group($results, 'nav.search.group.customers');
        $subscriptions = $this->group($results, 'nav.search.group.subscriptions');

        $this->assertCount(1, $customers);
        $this->assertSame(self::SHARED_NAME, (string) $customers[0]->title);
        $this->assertStringContainsString('/admin/customers/11', $customers[0]->url);
        $this->assertStringContainsString('dana@mine.test', implode(' ', $customers[0]->details));

        $this->assertCount(1, $subscriptions);
        $this->assertStringContainsString('/admin/subscriptions/', $subscriptions[0]->url);
    }

    public function test_another_shops_rows_never_appear(): void
    {
        foreach (['dana', 'theirs.test', '0502222222', '5002', '#5002'] as $term) {
            $flat = $this->flatten($this->search($term));

            $this->assertStringNotContainsString('theirs.test', $flat, "'{$term}' leaked the other shop's email");
            $this->assertStringNotContainsString('0502222222', $flat, "'{$term}' leaked the other shop's phone");
            $this->assertStringNotContainsString('5002', $flat, "'{$term}' leaked the other shop's order");
        }
    }

    public function test_it_finds_customers_by_phone_and_email(): void
    {
        $this->assertCount(1, $this->group($this->search('0501111'), 'nav.search.group.customers'));
        $this->assertCount(1, $this->group($this->search('dana@mine'), 'nav.search.group.customers'));
    }

    public function test_it_finds_orders_by_number_with_or_without_the_hash(): void
    {
        foreach (['5001', '#5001'] as $term) {
            $orders = $this->group($this->search($term), 'nav.search.group.orders');

            $this->assertCount(1, $orders, "'{$term}' should find the order");
            $this->assertSame(__('nav.search.order', ['number' => '5001']), (string) $orders[0]->title);
        }
    }

    public function test_it_finds_a_renewal_order_through_its_payment(): void
    {
        $this->payment($this->mine, childOrder: '7788');
        $this->payment($this->theirs, childOrder: '7789');

        $orders = $this->group($this->search('778'), 'nav.search.group.orders');

        $this->assertCount(1, $orders);
        $this->assertSame(__('nav.search.order', ['number' => '7788']), (string) $orders[0]->title);
        $this->assertStringContainsString('/admin/payments/', $orders[0]->url);
    }

    public function test_it_finds_shopify_rail_contracts_of_the_bound_shop_only(): void
    {
        $this->contract($this->mine, 'Noa Contract', 'noa@mine.test', '901');
        $this->contract($this->theirs, 'Noa Contract', 'noa@theirs.test', '902');

        $results = $this->search('noa');

        $this->assertCount(1, $this->group($results, 'nav.search.group.subscriptions'));
        $this->assertCount(1, $this->group($results, 'nav.search.group.customers'));
        $this->assertStringNotContainsString('theirs.test', $this->flatten($results));
    }

    public function test_like_wildcards_in_the_term_are_literal(): void
    {
        $this->plan($this->mine, 'Percent 50% Off', 'p50@mine.test', null, ref: '33', order: '6001');
        $this->plan($this->mine, 'Under a_b Score', 'ub@mine.test', null, ref: '44', order: '6002');
        $this->plan($this->mine, 'Bang x!y Name', 'bang@mine.test', null, ref: '55', order: '6003');

        // "%%" and "__" would match every row as wildcards; literal, they match none.
        $this->assertSame([], $this->group($this->search('%%'), 'nav.search.group.customers'));
        $this->assertSame([], $this->group($this->search('__'), 'nav.search.group.customers'));

        $this->assertCount(1, $this->group($this->search('50%'), 'nav.search.group.customers'));
        $this->assertCount(1, $this->group($this->search('a_b'), 'nav.search.group.customers'));
        $this->assertCount(1, $this->group($this->search('x!y'), 'nav.search.group.customers'));
        $this->assertSame('%a!_b!%!!%', TenantGlobalSearchProvider::containsPattern('a_b%!'));
    }

    public function test_a_single_character_opens_nothing(): void
    {
        $this->assertNull((new TenantGlobalSearchProvider)->getResults('d'));
        $this->assertNull((new TenantGlobalSearchProvider)->getResults('  '));
    }

    public function test_no_bound_shop_fails_closed(): void
    {
        Tenant::clear();

        $results = (new TenantGlobalSearchProvider)->getResults('dana');

        $this->assertInstanceOf(GlobalSearchResults::class, $results);
        $this->assertTrue($results->getCategories()->isEmpty());
    }

    public function test_each_group_is_capped(): void
    {
        for ($i = 0; $i < TenantGlobalSearchProvider::GROUP_LIMIT + 4; $i++) {
            $this->plan($this->mine, 'Bulk Person '.$i, "bulk{$i}@mine.test", null, ref: (string) (100 + $i), order: (string) (9000 + $i));
        }

        $results = $this->search('bulk');

        $this->assertCount(TenantGlobalSearchProvider::GROUP_LIMIT, $this->group($results, 'nav.search.group.customers'));
        $this->assertCount(TenantGlobalSearchProvider::GROUP_LIMIT, $this->group($results, 'nav.search.group.subscriptions'));
    }

    public function test_the_query_count_does_not_grow_with_the_matches(): void
    {
        $this->plan($this->mine, 'Person One', 'person@mine.test', null, ref: '299', order: '7999');
        $few = $this->queriesFor('person');

        for ($i = 0; $i < 12; $i++) {
            $this->plan($this->mine, 'Person '.$i, "person{$i}@mine.test", null, ref: (string) (300 + $i), order: (string) (8000 + $i));
        }

        // A fuller group can SKIP a fallback query (contracts, payments), never add one.
        $many = $this->queriesFor('person');
        $this->assertLessThanOrEqual($few, $many, "the search grew from {$few} to {$many} queries");
        $this->assertLessThanOrEqual(self::MAX_QUERIES, $few);
    }

    public function test_the_topbar_box_renders_grouped_results(): void
    {
        Livewire::test(TopbarSearch::class)
            ->set('search', 'dana')
            ->assertSee(__('nav.search.group.customers'))
            ->assertSee(self::SHARED_NAME)
            ->assertDontSee('dana@theirs.test');
    }

    public function test_the_admin_shell_draws_the_search_box(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee(__('nav.search.placeholder'))
            ->assertSee('rc-topbar-search', false);
    }

    // ---------------------------------------------------------------- helpers

    private function search(string $term): GlobalSearchResults
    {
        $results = (new TenantGlobalSearchProvider)->getResults($term);
        $this->assertNotNull($results);

        return $results;
    }

    /** @return list<GlobalSearchResult> */
    private function group(GlobalSearchResults $results, string $key): array
    {
        return array_values((array) ($results->getCategories()->get(__($key)) ?? []));
    }

    private function flatten(GlobalSearchResults $results): string
    {
        return $results->getCategories()
            ->flatMap(fn ($items) => collect($items)->map(fn (GlobalSearchResult $r): string => $r->title.' '.$r->url.' '.implode(' ', $r->details)))
            ->implode("\n");
    }

    private function queriesFor(string $term): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new TenantGlobalSearchProvider)->getResults($term);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    private function plan(Shop $shop, string $name, string $email, ?string $phone, string $ref, string $order): void
    {
        $plan = new InstallmentPlan;
        $plan->fill([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'total_amount' => 100,
            'installment_amount' => 100,
            'currency' => 'ILS',
            'public_id' => (string) Str::ulid(),
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'shopify_customer_id' => $ref,
        ]);
        $plan->forceFill([
            'shop_id' => $shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
            'external_order_id' => $order,
        ])->save();
    }

    private function payment(Shop $shop, string $childOrder): void
    {
        (new PaymentLedger)->forceFill([
            'shop_id' => $shop->getKey(),
            'charge_context' => 'recurring',
            'idempotency_key' => 'search-test:'.$childOrder,
            'amount' => 89,
            'currency' => 'ILS',
            'status' => PaymentLedger::STATUS_SUCCEEDED,
            'child_order_id' => $childOrder,
            'customer_name' => 'Renewal Buyer',
        ])->save();
    }

    private function contract(Shop $shop, string $name, string $email, string $customerId): void
    {
        (new SubscriptionContract)->forceFill([
            'shop_id' => $shop->getKey(),
            'shopify_gid' => 'gid://shopify/SubscriptionContract/'.$customerId,
            'shopify_customer_gid' => 'gid://shopify/Customer/'.$customerId,
            'status' => SubscriptionContract::STATUS_ACTIVE,
            'customer_name' => $name,
            'customer_email' => $email,
        ])->save();
    }
}
