<?php

namespace Tests\Feature\ShopifySubscriptions;

use App\Domain\ShopifySubscriptions\ContractScheduleAdvancer;
use App\Domain\ShopifySubscriptions\Jobs\AdvanceContractScheduleJob;
use App\Domain\ShopifySubscriptions\Jobs\BillingAttemptJob;
use App\Models\ActivityEvent;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Models\WebhookEvent;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\Shopify\Webhooks\SubscriptionWebhookHandler;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * A PAID CYCLE MOVES THE CONTRACT ON.
 *
 * Shopify sets a contract's first billing date and never another — the app must move it
 * after each paid cycle. Before this, every Shopify Payments subscription billed once and
 * then sat on its paid date forever: never charged again, and "Charge now" answering that
 * the cycle already had an attempt.
 *
 * The next date follows the merchant's renewal anchor exactly as the PayPlus rail does.
 */
final class ContractScheduleAdvanceTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const GID = 'gid://shopify/SubscriptionContract/9911';

    private RecordingShopifyClient $recorder;

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        Carbon::setTestNow();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_success_webhook_queues_the_move_for_the_paid_attempt(): void
    {
        Bus::fake([AdvanceContractScheduleJob::class]);
        $shop = $this->shop();
        $contract = $this->contract($shop, '2026-10-16 20:00:00');
        $attempt = $this->attempt($shop, $contract, '2026-10-16', SubscriptionBillingAttempt::STATUS_REQUESTED);

        $event = WebhookEvent::create([
            'shop_id' => (int) $shop->getKey(),
            'source' => WebhookEvent::SOURCE_SHOPIFY,
            'topic' => 'subscription_billing_attempts/success',
            'webhook_id' => 'wh-success',
            'raw_payload' => ['idempotency_key' => $attempt->idempotency_key, 'order_id' => 7463218872623, 'subscription_contract_id' => 9911],
            'hmac_valid' => true,
            'received_at' => now(),
        ]);

        Tenant::run($shop, fn () => app(SubscriptionWebhookHandler::class)->handle($event));

        Bus::assertDispatched(AdvanceContractScheduleJob::class, fn (AdvanceContractScheduleJob $job): bool => $job->attemptId === (int) $attempt->getKey());
    }

    public function test_a_cycle_paid_early_moves_the_contract_to_the_next_cycle(): void
    {
        // The screenshot's case: "Charge now" on 16 Sep for the cycle due 16 Oct.
        Carbon::setTestNow(Carbon::parse('2026-09-16 21:23:10', 'UTC'));
        $shop = $this->shop();
        $contract = $this->contract($shop, '2026-10-16 20:00:00');
        $attempt = $this->attempt($shop, $contract, '2026-10-16');
        $this->fakeGraphql([$this->readBack('2026-10-16T20:00:00Z'), $this->dateAnswer('2026-11-16T20:00:00Z')]);

        $this->runJob($shop, $attempt);

        $this->assertStringContainsString('subscriptionContractSetNextBillingDate', $this->recorder->graphqlCalls[1]['query']);
        $this->assertStringStartsWith('2026-11-16', (string) $this->recorder->graphqlCalls[1]['variables']['date']);
        $this->assertSame('2026-11-16', $contract->fresh()->next_billing_date->toDateString());
        $this->assertSame(1, ActivityEvent::query()->withoutGlobalScopes()->where('kind', ContractScheduleAdvancer::KIND_ADVANCED)->count());
    }

    public function test_a_contract_already_past_the_paid_cycle_is_not_moved_again(): void
    {
        $shop = $this->shop();
        $contract = $this->contract($shop, '2026-10-16 20:00:00');
        $attempt = $this->attempt($shop, $contract, '2026-10-16');
        // The second delivery of the webhook: Shopify already holds the next date.
        $this->fakeGraphql([$this->readBack('2026-11-16T20:00:00Z')]);

        $this->runJob($shop, $attempt);

        $this->assertCount(1, $this->recorder->graphqlCalls, 'read only — nothing moved twice');
    }

    public function test_the_next_date_follows_the_stores_renewal_anchor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 10:00:00', 'UTC'));
        $shop = $this->shop();
        $contract = $this->contract($shop, '2026-10-16 20:00:00');
        $advancer = app(ContractScheduleAdvancer::class);

        $cases = [
            // anchor => [scheduled (paid) date, expected next]
            MerchantBillingSettings::ANCHOR_CYCLE => [
                ['2026-10-16 20:00:00', '2026-11-16'], // paid early: the schedule holds
                ['2026-08-04 00:00:00', '2026-09-04'], // paid late: every missed cycle is still owed
            ],
            MerchantBillingSettings::ANCHOR_SKIP_MISSED => [
                ['2026-10-16 20:00:00', '2026-11-16'],
                ['2026-06-04 00:00:00', '2026-10-04'], // the first date of the schedule still ahead
            ],
            MerchantBillingSettings::ANCHOR_CHARGE_DATE => [
                ['2026-10-16 20:00:00', '2026-11-16'],
                ['2026-09-01 00:00:00', '2026-10-17'], // a cycle from the day it was paid
            ],
        ];

        foreach ($cases as $anchor => $rows) {
            foreach ($rows as [$scheduled, $expected]) {
                $next = Tenant::run($shop, function () use ($anchor, $advancer, $contract, $scheduled) {
                    MerchantBillingSettings::current()->forceFill(['renewal_anchor' => $anchor])->save();

                    return $advancer->nextDate($contract, Carbon::parse($scheduled, 'UTC'));
                });

                $this->assertSame($expected, $next->toDateString(), "{$anchor} from {$scheduled}");
            }
        }
    }

    public function test_the_scanner_moves_a_paid_cycle_on_and_charges_at_most_once_a_day(): void
    {
        Queue::fake();
        $shop = $this->shop();

        // Due, and its cycle already PAID (the August contracts on the pilot store).
        $stuck = $this->contract($shop, now()->subDays(40)->format('Y-m-d 00:00:00'), 'gid://shopify/SubscriptionContract/1');
        $paid = $this->attempt($shop, $stuck, $stuck->next_billing_date->toDateString());

        // Due, owing a cycle, but charged for the previous one two hours ago.
        $recent = $this->contract($shop, now()->subDay()->format('Y-m-d 00:00:00'), 'gid://shopify/SubscriptionContract/2');
        $this->attempt($shop, $recent, now()->subMonth()->toDateString(), requestedAt: now()->subHours(2));

        // Due, last charged long ago: billed as usual.
        $owing = $this->contract($shop, now()->subDay()->format('Y-m-d 00:00:00'), 'gid://shopify/SubscriptionContract/3');
        $this->attempt($shop, $owing, now()->subMonths(2)->toDateString(), requestedAt: now()->subMonth());

        $this->artisan('shopify-subscriptions:dispatch-due')->assertExitCode(0);

        Queue::assertPushed(AdvanceContractScheduleJob::class, 1);
        Queue::assertPushed(AdvanceContractScheduleJob::class, fn (AdvanceContractScheduleJob $job): bool => $job->attemptId === (int) $paid->getKey());
        Queue::assertPushed(BillingAttemptJob::class, 1);
        Queue::assertPushed(BillingAttemptJob::class, fn (BillingAttemptJob $job): bool => $job->contractId === (int) $owing->getKey());
    }

    // === Fixtures ===

    private function shop(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'advance.myshopify.com',
            'name' => 'Advance',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
            'subscription_rail' => Shop::RAIL_SHOPIFY_PAYMENTS,
        ]);
        $shop->forceFill(['shopify_access_token' => 'tok'])->save();

        return $shop->fresh();
    }

    private function contract(Shop $shop, string $nextBillingDate, string $gid = self::GID): SubscriptionContract
    {
        $contract = new SubscriptionContract;
        $contract->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'shopify_gid' => $gid,
            'shopify_customer_gid' => 'gid://shopify/Customer/77',
            'status' => SubscriptionContract::STATUS_ACTIVE,
            'interval' => 'MONTH',
            'interval_count' => 1,
            'next_billing_date' => Carbon::parse($nextBillingDate, 'UTC'),
            'currency' => 'ILS',
            'amount' => 73.00,
            'synced_at' => now(),
        ])->save();

        return $contract->fresh();
    }

    private function attempt(
        Shop $shop,
        SubscriptionContract $contract,
        string $cycle,
        string $status = SubscriptionBillingAttempt::STATUS_SUCCEEDED,
        ?Carbon $requestedAt = null,
    ): SubscriptionBillingAttempt {
        $attempt = new SubscriptionBillingAttempt;
        $attempt->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'subscription_contract_id' => (int) $contract->getKey(),
            'billing_cycle_key' => $cycle,
            'idempotency_key' => sprintf('subattempt:%d:%d:%s', $shop->getKey(), $contract->getKey(), $cycle),
            'status' => $status,
            'requested_at' => $requestedAt ?? now()->subMinute(),
            'resolved_at' => $status === SubscriptionBillingAttempt::STATUS_SUCCEEDED ? now() : null,
        ])->save();

        return $attempt;
    }

    private function runJob(Shop $shop, SubscriptionBillingAttempt $attempt): void
    {
        $job = new AdvanceContractScheduleJob((int) $shop->getKey(), (int) $attempt->getKey());
        $job->middleware()[0]->handle($job, fn () => $job->handle(app(ContractScheduleAdvancer::class)));
    }

    /** @param list<array<string, mixed>> $responses */
    private function fakeGraphql(array $responses): void
    {
        $this->recorder = new RecordingShopifyClient;
        $this->recorder->graphqlResponses = $responses;
        $recorder = $this->recorder;

        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $recorder);
    }

    /** @return array<string, mixed> */
    private function readBack(string $nextBillingDate): array
    {
        return ['data' => ['subscriptionContract' => [
            'id' => self::GID,
            'status' => 'ACTIVE',
            'currencyCode' => 'ILS',
            'nextBillingDate' => $nextBillingDate,
            'billingPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1],
            'deliveryPrice' => ['amount' => '73.00'],
            'customer' => ['id' => 'gid://shopify/Customer/77'],
            'lines' => ['edges' => []],
        ]]];
    }

    /** @return array<string, mixed> */
    private function dateAnswer(string $nextBillingDate): array
    {
        return ['data' => ['subscriptionContractSetNextBillingDate' => [
            'contract' => [
                'id' => self::GID,
                'status' => 'ACTIVE',
                'nextBillingDate' => $nextBillingDate,
                'currencyCode' => 'ILS',
                'billingPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1],
            ],
            'userErrors' => [],
        ]]];
    }
}
