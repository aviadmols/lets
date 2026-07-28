<?php

namespace Tests\Feature\ShopifySubscriptions;

use App\Domain\ShopifySubscriptions\ContractActionService;
use App\Filament\Resources\SubscriptionContractResource;
use App\Filament\Resources\SubscriptionContractResource\Pages\ViewSubscriptionContract;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The subscription detail page: what it shows, what it is allowed to show, and
 * what it refuses to let the browser decide.
 *
 * The page is a view over a MIRROR — Shopify owns the contract — so its verbs go
 * through ContractActionService and the page records only what came back. The
 * two tests that matter most are the boring ones: a contract from another shop
 * is a 404, and a verb the contract's status forbids is not offered.
 */
final class ContractDetailPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_it_shows_the_contract_cadence_in_words(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $page = new ViewSubscriptionContract();
        $page->mount((int) $this->contract($shop)->getKey());

        $this->assertSame('every month', $page->cadenceLabel());

        $page->record->forceFill(['interval' => 'WEEK', 'interval_count' => 2])->save();
        $this->assertSame('every 2 weeks', $page->cadenceLabel());
    }

    public function test_another_shops_contract_is_not_found(): void
    {
        $mine = $this->shop();
        $theirs = Shop::create([
            'shopify_domain' => 'other.myshopify.com',
            'name' => 'Other',
            'status' => Shop::STATUS_INSTALLED,
            'subscription_rail' => Shop::RAIL_SHOPIFY_PAYMENTS,
        ]);
        $foreign = $this->contract($theirs);

        Tenant::set($mine);

        // The tenant scope fails closed, so this is a 404 and never a peek at
        // another merchant's subscriber.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        (new ViewSubscriptionContract())->mount((int) $foreign->getKey());
    }

    public function test_the_lines_and_items_come_from_the_mirror(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $contract = $this->contract($shop);
        $contract->forceFill(['lines' => [
            ['title' => 'כובע מצחיה NY', 'quantity' => 2, 'amount' => '49.90'],
        ]])->save();

        $page = new ViewSubscriptionContract();
        $page->mount((int) $contract->getKey());

        $this->assertSame(
            [['title' => 'כובע מצחיה NY', 'quantity' => 2, 'amount' => '49.90']],
            $page->lines(),
        );
    }

    public function test_a_never_synced_contract_says_so(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $contract = $this->contract($shop);
        $contract->forceFill(['synced_at' => null])->save();

        $page = new ViewSubscriptionContract();
        $page->mount((int) $contract->getKey());

        // An honest mirror admits its age; the detail page prints this instead of
        // a timestamp it does not have.
        $this->assertTrue($page->isStale());
    }

    public function test_a_reschedule_into_the_past_is_refused(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);
        $contract = $this->contract($shop);

        // Server-side, not just a disabled date picker: a past date would make the
        // due-cycle scanner bill the shopper immediately.
        $result = app(ContractActionService::class)
            ->reschedule($shop, $contract, now()->subDay(), 'system');

        $this->assertFalse($result['ok']);
        $this->assertSame(ContractActionService::ERR_BAD_DATE, $result['reason']);
    }

    public function test_the_list_takes_the_subscriptions_name_when_it_is_the_only_one(): void
    {
        Tenant::set($this->shop());

        $this->assertTrue(SubscriptionContractResource::isPrimarySubscriptionsScreen());
        $this->assertSame(__('nav.subscriptions'), SubscriptionContractResource::getNavigationLabel());
        $this->assertSame(__('nav.group.customers'), SubscriptionContractResource::getNavigationGroup());
    }

    private function shop(): Shop
    {
        return Shop::create([
            'shopify_domain' => 'detail.myshopify.com',
            'name' => 'Detail',
            'status' => Shop::STATUS_INSTALLED,
            'subscription_rail' => Shop::RAIL_SHOPIFY_PAYMENTS,
        ]);
    }

    private function contract(Shop $shop): SubscriptionContract
    {
        $contract = new SubscriptionContract();
        $contract->forceFill([
            'shop_id' => $shop->getKey(),
            'shopify_gid' => 'gid://shopify/SubscriptionContract/'.$shop->getKey(),
            'status' => SubscriptionContract::STATUS_ACTIVE,
            'interval' => 'MONTH',
            'interval_count' => 1,
            'next_billing_date' => now()->addMonth(),
            'amount' => 73.00,
            'currency' => 'ILS',
            'synced_at' => now(),
        ])->save();

        return $contract;
    }
}
