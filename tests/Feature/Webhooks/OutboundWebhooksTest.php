<?php

namespace Tests\Feature\Webhooks;

use App\Domain\Billing\Ledger;
use App\Domain\Brand\SafeSiteFetcher;
use App\Domain\OutboundWebhooks\Jobs\DeliverWebhookJob;
use App\Domain\OutboundWebhooks\Models\WebhookDelivery;
use App\Domain\OutboundWebhooks\Models\WebhookEndpoint;
use App\Domain\OutboundWebhooks\WebhookDeliverer;
use App\Domain\OutboundWebhooks\WebhookPublisher;
use App\Domain\OutboundWebhooks\WebhookSender;
use App\Domain\OutboundWebhooks\WebhookTopic;
use App\Events\ChargeFailed;
use App\Jobs\Privacy\RedactCustomerData;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublicDnsSafeSiteFetcher;
use Tests\TestCase;

/**
 * Outbound webhooks: every paid order and every recurring charge — won or lost —
 * reaches the merchant's URL as ONE signed JSON, exactly once per event, only for
 * the shop it belongs to, and never at an address the SSRF walls refuse.
 */
final class OutboundWebhooksTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const URL = 'https://hooks.example.com/lets';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['shopify_domain' => 'hooks.myshopify.com', 'name' => 'Hooks', 'status' => Shop::STATUS_ACTIVE]);
        Tenant::set($this->shop);
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    private function endpoint(array $topics = WebhookTopic::ALL, string $url = self::URL): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'name' => 'Make',
            'url' => $url,
            'secret' => 'whsec_test',
            'topics' => $topics,
            'is_active' => true,
        ]);
    }

    private function plan(): InstallmentPlan
    {
        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::RECURRING->value,
            'customer_name' => 'Dana Levi',
            'customer_email' => 'dana@example.com',
            'installment_amount' => 49.90,
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'currency' => 'ILS',
            'next_charge_at' => now()->addMonth(),
            'meta' => ['item_title' => 'Coffee club'],
        ]);
        $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

        return $plan;
    }

    private function succeed(string $context, ?int $planId = null, string $key = 'k-1'): PaymentLedger
    {
        $row = Ledger::open((int) $this->shop->getKey(), $context, $key, 49.90, 'ILS', ['plan_id' => $planId]);

        return Ledger::transition($row, LedgerStatus::SUCCEEDED, ['payplus_transaction_uid' => 'txn-1']);
    }

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        $sent = Http::recorded()->last();

        return json_decode($sent[0]->body(), true);
    }

    public function test_a_recurring_charge_is_posted_signed_with_its_subscription(): void
    {
        $this->endpoint();
        $plan = $this->plan();

        $row = $this->succeed(PaymentLedger::CONTEXT_RECURRING, $plan->id);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            [$t, $v1] = array_map(
                static fn (string $part): string => explode('=', $part, 2)[1],
                explode(',', $request->header(WebhookSender::HEADER_SIGNATURE)[0]),
            );

            return $request->url() === self::URL
                && $request->header(WebhookSender::HEADER_TOPIC)[0] === WebhookTopic::RECURRING_SUCCEEDED
                && hash_equals(hash_hmac('sha256', $t.'.'.$request->body(), 'whsec_test'), $v1);
        });

        $body = $this->sentBody();
        $this->assertSame(WebhookTopic::RECURRING_SUCCEEDED, $body['topic']);
        $this->assertFalse($body['test']);
        $this->assertSame($row->id, $body['data']['charge']['id']);
        $this->assertSame(49.9, $body['data']['charge']['amount']);
        $this->assertSame('dana@example.com', $body['data']['customer']['email']);
        $this->assertSame('Coffee club', $body['data']['subscription']['product_title']);
        $this->assertSame('monthly', $body['data']['subscription']['frequency']);

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame(WebhookDelivery::STATUS_SUCCEEDED, $delivery->status);
        $this->assertSame(200, $delivery->response_status);
    }

    public function test_a_new_purchase_is_order_paid_and_an_unsubscribed_endpoint_hears_nothing(): void
    {
        $this->endpoint([WebhookTopic::RECURRING_FAILED]);
        $this->succeed(PaymentLedger::CONTEXT_GATEWAY);
        Http::assertNothingSent();

        $this->endpoint([WebhookTopic::ORDER_PAID]);
        $this->succeed(PaymentLedger::CONTEXT_UPSELL, null, 'k-2');

        Http::assertSentCount(1);
        $this->assertSame(WebhookTopic::ORDER_PAID, $this->sentBody()['topic']);
        $this->assertNull($this->sentBody()['data']['subscription']);
    }

    public function test_the_same_event_announced_twice_is_sent_once(): void
    {
        $this->endpoint();
        $row = $this->succeed(PaymentLedger::CONTEXT_GATEWAY);

        app(WebhookPublisher::class)->publish(
            (int) $this->shop->id,
            WebhookTopic::ORDER_PAID,
            sprintf('ledger:%d:succeeded', $row->id),
            ['kind' => 'ledger', 'ledger_id' => $row->id],
        );

        Http::assertSentCount(1);
        $this->assertSame(1, WebhookDelivery::query()->count());
    }

    public function test_a_rolled_back_charge_announces_nothing(): void
    {
        $this->endpoint();

        try {
            DB::transaction(function (): void {
                $this->succeed(PaymentLedger::CONTEXT_GATEWAY);
                throw new \RuntimeException('charge unwound');
            });
        } catch (\RuntimeException) {
        }

        Http::assertNothingSent();
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_a_declined_cycle_is_recurring_charge_failed_once_per_attempt(): void
    {
        $this->endpoint();
        $plan = $this->plan();
        $payment = InstallmentPayment::create([
            'plan_id' => $plan->id,
            'payment_type' => 'recurring',
            'sequence' => 3,
            'amount' => 49.90,
            'currency' => 'ILS',
            'status' => 'failed',
            'attempt_count' => 1,
            'next_retry_at' => now()->addDays(3),
        ]);

        ChargeFailed::dispatch((int) $this->shop->id, $plan, $payment, '1', 'Card declined', true);
        ChargeFailed::dispatch((int) $this->shop->id, $plan, $payment, '1', 'Card declined', true);

        Http::assertSentCount(1);
        $charge = $this->sentBody()['data']['charge'];
        $this->assertSame(WebhookTopic::RECURRING_FAILED, $this->sentBody()['topic']);
        $this->assertSame('Card declined', $charge['failure_message']);
        $this->assertTrue($charge['will_retry']);
        $this->assertNotNull($charge['next_retry_at']);
        $this->assertSame(1, $charge['attempt']);

        // Attempt 2 is a new event.
        $payment->forceFill(['attempt_count' => 2])->save();
        ChargeFailed::dispatch((int) $this->shop->id, $plan, $payment, '1', 'Card declined', false);
        Http::assertSentCount(2);
    }

    public function test_a_shopify_payments_cycle_outcome_is_announced(): void
    {
        $this->endpoint();
        $contract = SubscriptionContract::create([
            'shopify_gid' => 'gid://shopify/SubscriptionContract/7',
            'status' => 'ACTIVE',
            'interval' => 'MONTH',
            'interval_count' => 1,
            'amount' => 80,
            'currency' => 'ILS',
            'customer_email' => 'noa@example.com',
        ]);
        $attempt = SubscriptionBillingAttempt::create([
            'subscription_contract_id' => $contract->id,
            'billing_cycle_key' => '2026-10-14',
            'idempotency_key' => 'sba-1',
            'status' => SubscriptionBillingAttempt::STATUS_SUCCEEDED,
            'resolved_at' => now(),
        ]);

        app(WebhookPublisher::class)->billingAttemptResolved($attempt);

        $body = $this->sentBody();
        $this->assertSame(WebhookTopic::RECURRING_SUCCEEDED, $body['topic']);
        $this->assertSame('shopify_payments', $body['data']['charge']['gateway']);
        $this->assertSame('monthly', $body['data']['subscription']['frequency']);
        $this->assertSame('noa@example.com', $body['data']['customer']['email']);
    }

    public function test_a_failed_delivery_retries_on_the_backoff_with_the_same_body(): void
    {
        // Its own URL: the 200 stub from setUp would answer first otherwise.
        $flaky = 'https://flaky.example.com/lets';
        Http::fake([$flaky => Http::sequence()->push('down', 503)->push('ok', 200)]);
        Bus::fake([DeliverWebhookJob::class]);
        $this->endpoint(WebhookTopic::ALL, $flaky);
        $row = $this->succeed(PaymentLedger::CONTEXT_GATEWAY);

        $delivery = WebhookDelivery::query()->sole();
        app(WebhookDeliverer::class)->attempt($delivery);

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_RETRYING, $delivery->status);
        $this->assertSame(503, $delivery->response_status);
        $this->assertSame(1, $delivery->endpoint->consecutive_failures);
        Bus::assertDispatched(DeliverWebhookJob::class, fn (DeliverWebhookJob $job): bool => $job->delay === WebhookDeliverer::BACKOFF[0]);

        $firstBody = $delivery->payload;
        app(WebhookDeliverer::class)->attempt($delivery);

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_SUCCEEDED, $delivery->status);
        $this->assertSame(2, $delivery->attempts);
        $this->assertSame($firstBody['event_id'], $this->sentBody()['event_id']);
        $this->assertSame($row->id, $this->sentBody()['data']['charge']['id']);
        $this->assertSame(0, $delivery->endpoint->fresh()->consecutive_failures);
    }

    public function test_a_url_that_resolves_to_a_private_address_is_never_contacted(): void
    {
        $this->app->instance(SafeSiteFetcher::class, new PublicDnsSafeSiteFetcher([
            'internal.example.com' => ['169.254.169.254'],
        ]));
        $this->endpoint(WebhookTopic::ALL, 'https://internal.example.com/hook');

        $this->succeed(PaymentLedger::CONTEXT_GATEWAY);

        Http::assertNothingSent();
        $this->assertStringStartsWith('blocked_url:', (string) WebhookDelivery::query()->sole()->error);
    }

    public function test_another_shops_endpoint_never_hears_this_shops_charge(): void
    {
        $other = Shop::create(['shopify_domain' => 'other.myshopify.com', 'name' => 'Other', 'status' => Shop::STATUS_ACTIVE]);
        Tenant::run($other, fn () => WebhookEndpoint::create([
            'name' => 'Theirs', 'url' => 'https://other.example.com/hook', 'secret' => 's', 'topics' => WebhookTopic::ALL,
        ]));

        $this->succeed(PaymentLedger::CONTEXT_GATEWAY);

        Http::assertNothingSent();
        $this->assertSame(0, WebhookDelivery::query()->count());
        $this->assertSame(0, Tenant::run($other, fn () => WebhookDelivery::query()->count()));
    }

    public function test_a_paused_endpoint_hears_nothing(): void
    {
        $this->endpoint()->forceFill(['is_active' => false])->save();

        $this->succeed(PaymentLedger::CONTEXT_GATEWAY);

        Http::assertNothingSent();
    }

    public function test_customer_redact_deletes_that_customers_deliveries_only(): void
    {
        $endpoint = $this->endpoint();
        foreach (['erase.me@example.com' => 'a', 'keep.me@example.com' => 'b'] as $email => $key) {
            WebhookDelivery::create([
                'webhook_endpoint_id' => $endpoint->id,
                'topic' => WebhookTopic::ORDER_PAID,
                'event_key' => 'ledger:'.$key,
                'payload' => ['data' => ['customer' => ['email' => $email, 'external_id' => null]]],
                'status' => WebhookDelivery::STATUS_SUCCEEDED,
            ]);
        }

        RedactCustomerData::dispatchSync($this->shop->id, ['customer' => ['id' => '555', 'email' => 'Erase.Me@example.com']]);

        $left = Tenant::run($this->shop, fn () => WebhookDelivery::query()->pluck('event_key')->all());
        $this->assertSame(['ledger:b'], $left);
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $endpoint = $this->endpoint();

        $raw = DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');

        $this->assertNotSame('whsec_test', $raw);
        $this->assertSame('whsec_test', $endpoint->fresh()->secret);
    }
}
