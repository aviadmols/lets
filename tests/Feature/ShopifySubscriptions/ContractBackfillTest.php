<?php

namespace Tests\Feature\ShopifySubscriptions;

use App\Domain\ShopifySubscriptions\ContractBackfill;
use App\Domain\ShopifySubscriptions\Jobs\BackfillContractsJob;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Models\WebhookEvent;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\Shopify\Webhooks\SubscriptionWebhookHandler;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * Pulling the contracts that already exist at Shopify.
 *
 * The mirror is fed by `subscription_contracts/create|update` webhooks, and a
 * webhook announces a CHANGE — never what already exists. A store that had live
 * subscribers before the app could listen (webhooks never registered, scopes not
 * granted, a dead token) would therefore show an empty screen forever, no matter
 * how long it waited. These tests pin the one path that closes that gap, and the
 * two ways it legitimately comes back with nothing.
 */
final class ContractBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_it_mirrors_every_contract_across_pages(): void
    {
        $shop = $this->shop();
        $this->fakeGraphql([
            $this->page([$this->contract('1', 'ACTIVE'), $this->contract('2', 'PAUSED')], hasNext: true),
            $this->page([$this->contract('3', 'ACTIVE')], hasNext: false),
        ]);

        $result = Tenant::run($shop, fn (): array => app(ContractBackfill::class)->run($shop));

        $this->assertSame(ContractBackfill::RESULT_OK, $result['result']);
        $this->assertSame(3, $result['mirrored']);
        $this->assertSame(2, $result['pages'], 'The cursor must be followed, or page 2 is silently lost.');

        $this->assertSame(3, SubscriptionContract::acrossAllTenants()->count());

        $mirrored = SubscriptionContract::acrossAllTenants()
            ->where('shopify_gid', 'gid://shopify/SubscriptionContract/1')->firstOrFail();
        $this->assertSame(SubscriptionContract::STATUS_ACTIVE, $mirrored->status);
        $this->assertSame('MONTH', $mirrored->interval);
        $this->assertSame('sub1@example.com', $mirrored->customer_email);
        $this->assertSame((int) $shop->getKey(), (int) $mirrored->shop_id);
    }

    public function test_running_it_twice_updates_the_same_rows_instead_of_duplicating_them(): void
    {
        $shop = $this->shop();

        $this->fakeGraphql([$this->page([$this->contract('1', 'ACTIVE')], hasNext: false)]);
        Tenant::run($shop, fn () => app(ContractBackfill::class)->run($shop));

        // Shopify's answer changed between runs; the mirror must follow, on the
        // SAME row — the (shop_id, shopify_gid) key is what makes a re-run safe
        // rather than a unique-index collision.
        $this->fakeGraphql([$this->page([$this->contract('1', 'CANCELLED')], hasNext: false)]);
        Tenant::run($shop, fn () => app(ContractBackfill::class)->run($shop));

        $this->assertSame(1, SubscriptionContract::acrossAllTenants()->count());
        $this->assertSame(
            SubscriptionContract::STATUS_CANCELLED,
            SubscriptionContract::acrossAllTenants()->firstOrFail()->status,
        );
    }

    public function test_a_missing_scope_is_reported_as_a_pending_approval_not_as_a_fault(): void
    {
        $shop = $this->shop();
        $this->failingGraphql('shopify.graphql_errors: Access denied for subscriptionContracts field.');

        $result = Tenant::run($shop, fn (): array => app(ContractBackfill::class)->run($shop));

        // The distinction is the merchant's: one is an approval they can chase,
        // the other is a bug they cannot.
        $this->assertSame(ContractBackfill::RESULT_DENIED, $result['result']);
        $this->assertSame(0, $result['mirrored']);
        $this->assertSame(0, SubscriptionContract::acrossAllTenants()->count());
    }

    public function test_protected_customer_data_costs_the_name_not_the_subscription(): void
    {
        $shop = $this->shop();

        // Protected customer data is a SEPARATE approval from the subscription
        // scopes. Shopify refuses the three customer fields and fails the whole
        // read; the contract itself is perfectly readable without them.
        $recorder = new RecordingShopifyClient();
        $recorder->graphqlResponses = [$this->page([$this->contractWithoutCustomerFields('1')], hasNext: false)];
        $recorder->graphqlThrowsOnce = new \RuntimeException(
            'shopify.graphql_errors: This app is not approved to use the email field.'
            .' This app is not approved to use the firstName field.'
        );
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $result = Tenant::run($shop, fn (): array => app(ContractBackfill::class)->run($shop));

        $this->assertSame(ContractBackfill::RESULT_OK, $result['result']);
        $this->assertSame(1, $result['mirrored'], 'A missing label is not a reason to lose the subscription.');

        $mirrored = SubscriptionContract::acrossAllTenants()->firstOrFail();
        $this->assertSame(SubscriptionContract::STATUS_ACTIVE, $mirrored->status);
        $this->assertNull($mirrored->customer_email);

        // The retry must drop the protected fields — asking again for what was
        // just refused would loop.
        $this->assertStringNotContainsString('email', $recorder->graphqlCalls[1]['query']);
    }

    public function test_any_other_failure_is_reported_as_a_failure(): void
    {
        $shop = $this->shop();
        $this->failingGraphql('shopify.graphql_failed — shop=1 status=500 body=oops');

        $result = Tenant::run($shop, fn (): array => app(ContractBackfill::class)->run($shop));

        $this->assertSame(ContractBackfill::RESULT_FAILED, $result['result']);
    }

    public function test_the_job_binds_its_own_tenant_so_a_re_run_is_an_update(): void
    {
        $shop = $this->shop();

        // No tenant bound by the caller — the job must bind its own, or the
        // mirror's tenant-scoped lookup finds nothing on the second run and the
        // insert collides with the (shop_id, shopify_gid) unique index.
        $this->fakeGraphql([$this->page([$this->contract('1', 'ACTIVE')], hasNext: false)]);
        $this->runJob($shop);

        $this->fakeGraphql([$this->page([$this->contract('1', 'PAUSED')], hasNext: false)]);
        $this->runJob($shop);

        $this->assertSame(1, SubscriptionContract::acrossAllTenants()->count());
        $this->assertSame(
            SubscriptionContract::STATUS_PAUSED,
            SubscriptionContract::acrossAllTenants()->firstOrFail()->status,
        );
    }

    public function test_a_contract_webhook_is_read_back_in_full(): void
    {
        $shop = $this->shop();

        // Shopify's subscription_contracts/create payload verbatim: a
        // NOTIFICATION, not a record. No nextBillingDate, no amount, no lines.
        $payload = [
            'admin_graphql_api_id' => 'gid://shopify/SubscriptionContract/1',
            'id' => 1,
            'billing_policy' => ['interval' => 'month', 'interval_count' => 1],
            'currency_code' => 'ILS',
            'customer_id' => 10226744000815,
            'status' => 'active',
        ];

        $recorder = new RecordingShopifyClient();
        $recorder->graphqlResponses = [
            ['data' => ['subscriptionContract' => $this->contract('1', 'ACTIVE')]],
        ];
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $event = WebhookEvent::create([
            'shop_id' => (int) $shop->getKey(),
            'source' => WebhookEvent::SOURCE_SHOPIFY,
            'topic' => 'subscription_contracts/create',
            'webhook_id' => 'wh-1',
            'raw_payload' => $payload,
            'hmac_valid' => true,
            'received_at' => now(),
        ]);

        Tenant::run($shop, fn () => app(SubscriptionWebhookHandler::class)->handle($event));

        $mirrored = SubscriptionContract::acrossAllTenants()->firstOrFail();

        // The load-bearing one: the due-cycle scanner filters on
        // next_billing_date, so a null here is a subscription that never bills.
        $this->assertNotNull($mirrored->next_billing_date, 'Without this the contract is never billed.');
        $this->assertSame('49.90', (string) $mirrored->amount);
        $this->assertNotNull($mirrored->lines);
    }

    public function test_a_failed_read_back_keeps_the_sparse_row(): void
    {
        $shop = $this->shop();

        $recorder = new RecordingShopifyClient();
        $recorder->graphqlThrows = new \RuntimeException('shopify.graphql_failed — status=500');
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $event = WebhookEvent::create([
            'shop_id' => (int) $shop->getKey(),
            'source' => WebhookEvent::SOURCE_SHOPIFY,
            'topic' => 'subscription_contracts/create',
            'webhook_id' => 'wh-2',
            'raw_payload' => [
                'admin_graphql_api_id' => 'gid://shopify/SubscriptionContract/9',
                'billing_policy' => ['interval' => 'month', 'interval_count' => 1],
                'currency_code' => 'ILS',
                'status' => 'active',
            ],
            'hmac_valid' => true,
            'received_at' => now(),
        ]);

        Tenant::run($shop, fn () => app(SubscriptionWebhookHandler::class)->handle($event));

        // A subscription we can only half-describe is still one the merchant has.
        $this->assertSame(1, SubscriptionContract::acrossAllTenants()->count());
        $this->assertSame(
            SubscriptionContract::STATUS_ACTIVE,
            SubscriptionContract::acrossAllTenants()->firstOrFail()->status,
        );
    }

    public function test_a_shop_back_on_the_payplus_rail_is_not_mirrored_into(): void
    {
        $shop = $this->shop(Shop::RAIL_PAYPLUS);
        $this->fakeGraphql([$this->page([$this->contract('1', 'ACTIVE')], hasNext: false)]);

        // The rail is re-checked at RUN time: a job queued while the shop was on
        // the Shopify rail must not write after the merchant switched back.
        $this->runJob($shop);

        $this->assertSame(0, SubscriptionContract::acrossAllTenants()->count());
    }

    // === Fixtures ===

    private function shop(string $rail = Shop::RAIL_SHOPIFY_PAYMENTS): Shop
    {
        return Shop::create([
            'shopify_domain' => 'backfill.myshopify.com',
            'name' => 'Backfill',
            'status' => Shop::STATUS_INSTALLED,
            'subscription_rail' => $rail,
            'shopify_app_key' => Shop::APP_CUSTOM,
        ]);
    }

    /** The job through its real entry point, with NO tenant bound by the caller. */
    private function runJob(Shop $shop): void
    {
        Tenant::clear();

        $job = new BackfillContractsJob((int) $shop->getKey());
        // TenantContext is job middleware; running it here is what the queue does.
        $job->middleware()[0]->handle($job, fn () => $job->handle(app(ContractBackfill::class)));
    }

    /** @return array<string, mixed> one contract node in the GraphQL read shape */
    private function contract(string $id, string $status): array
    {
        return [
            'id' => 'gid://shopify/SubscriptionContract/'.$id,
            'status' => $status,
            'currencyCode' => 'ILS',
            'nextBillingDate' => '2026-08-15T00:00:00Z',
            'billingPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1],
            'deliveryPrice' => ['amount' => '49.90'],
            'customer' => [
                'id' => 'gid://shopify/Customer/'.$id,
                'email' => 'sub'.$id.'@example.com',
                'firstName' => 'Sub',
                'lastName' => $id,
            ],
            'lines' => ['edges' => [
                ['node' => ['title' => 'כובע מצחיה NY', 'quantity' => 1, 'currentPrice' => ['amount' => '49.90']]],
            ]],
        ];
    }

    /** The same node as Shopify returns it when the customer fields are dropped. */
    private function contractWithoutCustomerFields(string $id): array
    {
        $node = $this->contract($id, 'ACTIVE');
        $node['customer'] = ['id' => 'gid://shopify/Customer/'.$id];

        return $node;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private function page(array $nodes, bool $hasNext): array
    {
        return ['data' => ['subscriptionContracts' => [
            'pageInfo' => ['hasNextPage' => $hasNext, 'endCursor' => 'CURSOR'],
            'edges' => array_map(static fn (array $n): array => ['node' => $n], $nodes),
        ]]];
    }

    /** @param array<int, array<string, mixed>> $pages answered in order */
    private function fakeGraphql(array $pages): void
    {
        $recorder = new RecordingShopifyClient();
        $recorder->graphqlResponses = $pages;
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);
    }

    private function failingGraphql(string $message): void
    {
        $recorder = new RecordingShopifyClient();
        $recorder->graphqlThrows = new \RuntimeException($message);
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);
    }
}
