<?php

namespace Tests\Feature\ShopifySubscriptions;

use App\Domain\ShopifySubscriptions\ContractActionService;
use App\Domain\ShopifySubscriptions\Jobs\BillingAttemptJob;
use App\Filament\Resources\SubscriptionContractResource;
use App\Filament\Resources\SubscriptionContractResource\Pages\ViewSubscriptionContract;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_the_row_link_carries_the_key_not_the_model(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);
        $contract = $this->contract($shop);

        // Passing the MODEL serialises the whole row into the URL, and mount()
        // then receives that JSON where an id belongs — which reaches Postgres as
        // a JSON blob compared against a bigint.
        $url = ViewSubscriptionContract::getUrl(['contract' => $contract->getKey()]);

        $this->assertStringEndsWith('/'.$contract->getKey(), $url);
        $this->assertStringNotContainsString('shopify_gid', $url);
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

    // === Charge now (bill the next payment immediately) ===

    public function test_charge_now_dispatches_the_billing_job_for_the_scheduled_cycle(): void
    {
        Queue::fake();
        $shop = $this->shop();
        Tenant::set($shop);
        $contract = $this->contract($shop);
        $cycleKey = $contract->next_billing_date->toDateString();

        $result = app(ContractActionService::class)->billNow($shop, $contract, 'system');

        $this->assertTrue($result['ok']);
        // Keyed on the SCHEDULED cycle, so when its date arrives the scanner finds
        // this attempt and never double-bills.
        Queue::assertPushed(BillingAttemptJob::class, fn (BillingAttemptJob $job): bool => $job->shopId === (int) $shop->getKey()
            && $job->contractId === (int) $contract->getKey()
            && $job->billingCycleKey === $cycleKey);
    }

    public function test_charge_now_refuses_a_cycle_that_already_has_an_attempt(): void
    {
        Queue::fake();
        $shop = $this->shop();
        Tenant::set($shop);
        $contract = $this->contract($shop);

        $attempt = new SubscriptionBillingAttempt();
        $attempt->forceFill([
            'shop_id' => $shop->getKey(),
            'subscription_contract_id' => $contract->getKey(),
            'billing_cycle_key' => $contract->next_billing_date->toDateString(),
            'idempotency_key' => 'subattempt:x',
            'status' => SubscriptionBillingAttempt::STATUS_REQUESTED,
            'requested_at' => now(),
        ])->save();

        $result = app(ContractActionService::class)->billNow($shop, $contract, 'system');

        $this->assertFalse($result['ok']);
        $this->assertSame(ContractActionService::ERR_ALREADY_REQUESTED, $result['reason']);
        Queue::assertNothingPushed();
    }

    public function test_charge_now_refuses_a_non_billable_contract(): void
    {
        Queue::fake();
        $shop = $this->shop();
        Tenant::set($shop);
        $contract = $this->contract($shop);
        $contract->forceFill(['status' => SubscriptionContract::STATUS_PAUSED])->save();

        $result = app(ContractActionService::class)->billNow($shop, $contract->fresh(), 'system');

        $this->assertFalse($result['ok']);
        $this->assertSame(ContractActionService::ERR_NOT_BILLABLE, $result['reason']);
        Queue::assertNothingPushed();
    }

    // === Customer label (protected customer data pending) ===

    public function test_a_customer_without_readable_name_shows_a_reference_and_a_link(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $contract = $this->contract($shop);
        $contract->forceFill(['shopify_customer_gid' => 'gid://shopify/Customer/777'])->save();

        $page = new ViewSubscriptionContract();
        $page->mount((int) $contract->getKey());

        // Name/email are protected customer data — until Shopify approves the
        // access, the page identifies the shopper by number + admin deep link
        // instead of a blank the merchant reads as a bug.
        $this->assertSame(__('shopify_subscriptions.detail.customer_ref', ['id' => '777']), $page->customerLabel());
        $this->assertSame('https://detail.myshopify.com/admin/customers/777', $page->customerAdminUrl());
        $this->assertTrue($page->customerAwaitsApproval());
    }

    public function test_a_readable_customer_name_wins_over_the_reference(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $contract = $this->contract($shop);
        $contract->forceFill([
            'shopify_customer_gid' => 'gid://shopify/Customer/777',
            'customer_name' => 'Dana Buyer',
        ])->save();

        $page = new ViewSubscriptionContract();
        $page->mount((int) $contract->getKey());

        $this->assertSame('Dana Buyer', $page->customerLabel());
        $this->assertFalse($page->customerAwaitsApproval());
    }

    // === The projected order schedule ===

    public function test_the_schedule_projects_from_the_cadence_and_numbers_past_the_paid_count(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $contract = $this->contract($shop);
        $contract->forceFill(['next_billing_date' => '2026-09-28', 'interval' => 'MONTH', 'interval_count' => 1])->save();

        // One PAID cycle (the checkout) ⇒ the next projected row is #2.
        $attempt = new SubscriptionBillingAttempt();
        $attempt->forceFill([
            'shop_id' => $shop->getKey(),
            'subscription_contract_id' => $contract->getKey(),
            'billing_cycle_key' => '2026-08-28',
            'idempotency_key' => 'subattempt:paid',
            'status' => SubscriptionBillingAttempt::STATUS_SUCCEEDED,
            'requested_at' => now(),
        ])->save();

        $page = new ViewSubscriptionContract();
        $page->mount((int) $contract->getKey());

        $rows = $page->upcomingCycles();

        $this->assertCount(ViewSubscriptionContract::UPCOMING_COUNT, $rows);
        $this->assertSame(2, $rows[0]['ordinal']);
        $this->assertSame('2026-09-28', $rows[0]['date']->toDateString());
        $this->assertTrue($rows[0]['actionable']);
        // Later rows are projections only — Shopify moves/bills the NEXT cycle only.
        $this->assertSame('2026-10-28', $rows[1]['date']->toDateString());
        $this->assertFalse($rows[1]['actionable']);
    }

    public function test_a_paused_contract_projects_dates_but_offers_no_actions(): void
    {
        $shop = $this->shop();
        Tenant::set($shop);

        $contract = $this->contract($shop);
        $contract->forceFill(['status' => SubscriptionContract::STATUS_PAUSED])->save();

        $page = new ViewSubscriptionContract();
        $page->mount((int) $contract->getKey());

        $rows = $page->upcomingCycles();
        $this->assertNotEmpty($rows);
        $this->assertFalse($rows[0]['actionable']);
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
