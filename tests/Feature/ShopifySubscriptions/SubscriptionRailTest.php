<?php

namespace Tests\Feature\ShopifySubscriptions;

use App\Domain\ShopifySubscriptions\ContractActionService;
use App\Domain\ShopifySubscriptions\ContractMirror;
use App\Domain\ShopifySubscriptions\Jobs\BillingAttemptJob;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Models\WebhookEvent;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\Shopify\Webhooks\SubscriptionWebhookHandler;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * The Shopify-Payments rail's load-bearing walls:
 *
 *   1. the mirror is ONE row per contract however the data arrives;
 *   2. one cycle can produce at most ONE billing attempt (three layers);
 *   3. a transport failure leaves the attempt UNKNOWN and never re-asks;
 *   4. verbs go to Shopify and the mirror records only Shopify's answer;
 *   5. the rail is inert on a shop that has no contracts (the public app).
 */
final class SubscriptionRailTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_webhook_and_graphql_shapes_mirror_to_one_row(): void
    {
        $shop = $this->shop();
        $mirror = new ContractMirror();

        Tenant::run($shop, function () use ($shop, $mirror): void {
            // The webhook shape (numeric id, snake_case)…
            $mirror->fromWebhook($shop, [
                'id' => 9911,
                'admin_graphql_api_id' => 'gid://shopify/SubscriptionContract/9911',
                'status' => 'active',
                'next_billing_date' => '2026-08-01T00:00:00Z',
                'billing_policy' => ['interval' => 'month', 'interval_count' => 1],
                'currency_code' => 'USD',
            ]);

            // …then the GraphQL shape of the SAME contract, richer.
            $mirror->fromGraphQl($shop, [
                'id' => 'gid://shopify/SubscriptionContract/9911',
                'status' => 'ACTIVE',
                'nextBillingDate' => '2026-08-01T00:00:00Z',
                'billingPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1],
                'currencyCode' => 'USD',
                'deliveryPrice' => ['amount' => '29.90'],
                'customer' => ['id' => 'gid://shopify/Customer/77', 'email' => 'dana@example.com',
                    'firstName' => 'Dana', 'lastName' => 'Buyer'],
            ]);

            $this->assertSame(1, SubscriptionContract::query()->count(), 'Two shapes, one contract, one row.');

            $row = SubscriptionContract::query()->firstOrFail();
            $this->assertSame('ACTIVE', $row->status);
            $this->assertSame('29.90', (string) $row->amount);
            // The sparse webhook must not have blanked the richer fields.
            $this->assertSame('dana@example.com', $row->customer_email);
        });
    }

    public function test_one_due_cycle_produces_exactly_one_billing_attempt(): void
    {
        $shop = $this->shop();
        $contract = $this->contract($shop);
        $this->fakeGraphql([
            ['data' => ['subscriptionBillingAttemptCreate' => [
                'subscriptionBillingAttempt' => ['id' => 'gid://shopify/SubscriptionBillingAttempt/1'],
                'userErrors' => [],
            ]]],
        ]);

        $job = new BillingAttemptJob((int) $shop->getKey(), (int) $contract->getKey(), '2026-08-01');
        Tenant::run($shop, fn () => $job->handle());
        // The redelivered twin — finds the row, never asks Shopify again.
        Tenant::run($shop, fn () => $job->handle());

        $this->assertSame(1, SubscriptionBillingAttempt::acrossAllTenants()->count());
        $this->assertCount(1, $this->recorder->graphqlCalls, 'Shopify must be asked exactly once per cycle.');
        // Our idempotency key rides on the mutation itself (layer 3).
        $sent = $this->recorder->graphqlCalls[0]['variables']['subscriptionBillingAttemptInput'] ?? [];
        $this->assertSame(
            sprintf('subattempt:%d:%d:2026-08-01', $shop->getKey(), $contract->getKey()),
            $sent['idempotencyKey'] ?? null,
        );
    }

    public function test_a_transport_failure_leaves_the_attempt_unknown_and_never_reasks(): void
    {
        $shop = $this->shop();
        $contract = $this->contract($shop);

        // A client whose graphql() throws — the request MAY have reached Shopify.
        $recorder = new RecordingShopifyClient();
        $recorder->graphqlThrows = new \RuntimeException('gateway timeout');
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);

        $job = new BillingAttemptJob((int) $shop->getKey(), (int) $contract->getKey(), '2026-08-01');
        Tenant::run($shop, fn () => $job->handle());

        $attempt = SubscriptionBillingAttempt::acrossAllTenants()->firstOrFail();
        // Same asymmetry as the invoicing module: unknown outcome is NOT retried
        // blindly — the row stays `requested` and the webhook resolves it.
        $this->assertSame(SubscriptionBillingAttempt::STATUS_REQUESTED, $attempt->status);

        Tenant::run($shop, fn () => $job->handle());
        $this->assertSame(1, SubscriptionBillingAttempt::acrossAllTenants()->count(), 'Never a second ask for the cycle.');
    }

    public function test_the_success_webhook_resolves_our_attempt_by_idempotency_key(): void
    {
        $shop = $this->shop();
        $contract = $this->contract($shop);

        $attempt = new SubscriptionBillingAttempt();
        $attempt->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'subscription_contract_id' => (int) $contract->getKey(),
            'billing_cycle_key' => '2026-08-01',
            'idempotency_key' => 'subattempt:test:1',
            'status' => SubscriptionBillingAttempt::STATUS_REQUESTED,
        ])->save();

        $event = new WebhookEvent();
        $event->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'source' => WebhookEvent::SOURCE_SHOPIFY,
            'topic' => 'subscription_billing_attempts/success',
            'raw_payload' => [
                'idempotency_key' => 'subattempt:test:1',
                'order_id' => 445566,
                'subscription_contract_id' => 9911,
            ],
            'received_at' => now(),
        ])->save();

        Tenant::run($shop, fn () => app(SubscriptionWebhookHandler::class)->handle($event));

        $fresh = $attempt->fresh();
        $this->assertSame(SubscriptionBillingAttempt::STATUS_SUCCEEDED, $fresh->status);
        $this->assertSame('gid://shopify/Order/445566', $fresh->shopify_order_gid);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_verbs_update_the_mirror_only_from_shopifys_answer(): void
    {
        $shop = $this->shop();
        $contract = $this->contract($shop);
        $this->fakeGraphql([
            ['data' => ['subscriptionContractPause' => [
                'contract' => [
                    'id' => (string) $contract->shopify_gid,
                    'status' => 'PAUSED',
                    'nextBillingDate' => '2026-08-01T00:00:00Z',
                    'currencyCode' => 'USD',
                    'billingPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1],
                ],
                'userErrors' => [],
            ]]],
        ]);

        $result = Tenant::run($shop, fn (): array => app(ContractActionService::class)
            ->pause($shop, $contract, 'customer'));

        $this->assertTrue($result['ok']);
        // The mirror says PAUSED because SHOPIFY said PAUSED — not because we did.
        $this->assertSame('PAUSED', $contract->fresh()->status);
    }

    public function test_a_rejected_verb_changes_nothing_locally(): void
    {
        $shop = $this->shop();
        $contract = $this->contract($shop);
        $this->fakeGraphql([
            ['data' => ['subscriptionContractPause' => [
                'contract' => null,
                'userErrors' => [['field' => 'id', 'message' => 'not pausable']],
            ]]],
        ]);

        $result = Tenant::run($shop, fn (): array => app(ContractActionService::class)
            ->pause($shop, $contract, 'customer'));

        $this->assertFalse($result['ok']);
        $this->assertSame(ContractActionService::ERR_SHOPIFY_REJECTED, $result['reason']);
        $this->assertSame('ACTIVE', $contract->fresh()->status, 'A refused verb must not touch the mirror.');
    }

    public function test_the_scanner_dispatches_only_due_billable_contracts(): void
    {
        Queue::fake();
        $shop = $this->shop();

        $this->contract($shop, gid: 'gid://shopify/SubscriptionContract/1', due: now()->subHour());          // due
        $this->contract($shop, gid: 'gid://shopify/SubscriptionContract/2', due: now()->addWeek());          // not yet
        $this->contract($shop, gid: 'gid://shopify/SubscriptionContract/3', due: now()->subHour(), status: SubscriptionContract::STATUS_PAUSED); // paused

        Artisan::call('shopify-subscriptions:dispatch-due');

        Queue::assertPushed(BillingAttemptJob::class, 1);
        Queue::assertPushed(BillingAttemptJob::class, fn (BillingAttemptJob $job): bool => $job->shopId === (int) $shop->getKey());
    }

    public function test_the_rail_is_inert_on_a_shop_with_no_contracts(): void
    {
        Queue::fake();
        $this->shop(); // a plain PayPlus shop — no contracts ever mirrored

        Artisan::call('shopify-subscriptions:dispatch-due');

        Queue::assertNothingPushed();
    }

    // === Helpers ===

    private RecordingShopifyClient $recorder;

    /** @param list<array<string, mixed>> $responses */
    private function fakeGraphql(array $responses): void
    {
        $this->recorder = new RecordingShopifyClient();
        $this->recorder->graphqlResponses = $responses;
        $recorder = $this->recorder;

        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);
    }

    private function shop(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'subs-rail.myshopify.com',
            'name' => 'Subs Rail',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->forceFill(['shopify_access_token' => 'tok'])->save();

        return $shop->fresh();
    }

    private function contract(
        Shop $shop,
        string $gid = 'gid://shopify/SubscriptionContract/9911',
        ?\Illuminate\Support\Carbon $due = null,
        string $status = SubscriptionContract::STATUS_ACTIVE,
    ): SubscriptionContract {
        $contract = new SubscriptionContract();
        $contract->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'shopify_gid' => $gid,
            'shopify_customer_gid' => 'gid://shopify/Customer/77',
            'status' => $status,
            'interval' => 'MONTH',
            'interval_count' => 1,
            'next_billing_date' => $due ?? now()->subHour(),
            'currency' => 'USD',
        ])->save();

        return $contract;
    }
}
