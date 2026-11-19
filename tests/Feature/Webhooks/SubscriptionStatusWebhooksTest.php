<?php

namespace Tests\Feature\Webhooks;

use App\Domain\OutboundWebhooks\Models\WebhookDelivery;
use App\Domain\OutboundWebhooks\Models\WebhookEndpoint;
use App\Domain\OutboundWebhooks\SamplePayloads;
use App\Domain\OutboundWebhooks\WebhookPayload;
use App\Domain\OutboundWebhooks\WebhookTopic;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The subscription.* topics: an outside app (agent.lets.co.il) mirrors each
 * subscription's status from them, and knows WHICH of its users it is through the
 * client_reference its checkout link carried.
 *
 * Pinned: activated once per subscription, ever; every status move announced with
 * the status it left; nothing announced for a move that rolled back; the
 * reference handed back untouched.
 */
final class SubscriptionStatusWebhooksTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const URL = 'https://agent.example.com/webhooks/lets';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['woocommerce_domain' => 'lets.example.com', 'name' => 'Lets', 'status' => Shop::STATUS_ACTIVE, 'platform' => Shop::PLATFORM_WOOCOMMERCE]);
        Tenant::set($this->shop);
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_first_payment_announces_activated_and_the_move(): void
    {
        $this->endpoint();
        $plan = $this->plan(PlanStatus::AWAITING_FIRST_PAYMENT, 'acc_42.sig');

        $plan->transitionTo(PlanStatus::ACTIVE);

        $this->assertEqualsCanonicalizing(
            [WebhookTopic::SUBSCRIPTION_STATUS_CHANGED, WebhookTopic::SUBSCRIPTION_ACTIVATED],
            WebhookDelivery::query()->pluck('topic')->all(),
        );

        $activated = $this->sent(WebhookTopic::SUBSCRIPTION_ACTIVATED);
        $this->assertNull($activated['data']['charge']);
        $this->assertSame('active', $activated['data']['subscription']['status']);
        $this->assertSame('acc_42.sig', $activated['data']['subscription']['client_reference']);
        $this->assertSame('awaiting_first_payment', $activated['data']['change']['previous_status']);
        $this->assertSame('active', $activated['data']['change']['status']);
        $this->assertSame('dana@example.com', $activated['data']['customer']['email']);
    }

    public function test_activated_is_sent_once_even_when_the_plan_comes_back(): void
    {
        $this->endpoint();
        $plan = $this->plan(PlanStatus::AWAITING_FIRST_PAYMENT);

        $plan->transitionTo(PlanStatus::ACTIVE);
        $plan->transitionTo(PlanStatus::PAUSED);
        $plan->transitionTo(PlanStatus::ACTIVE);

        $this->assertSame(1, WebhookDelivery::query()->where('topic', WebhookTopic::SUBSCRIPTION_ACTIVATED)->count());
        $this->assertSame(3, WebhookDelivery::query()->where('topic', WebhookTopic::SUBSCRIPTION_STATUS_CHANGED)->count());
    }

    public function test_a_cancellation_says_what_it_left(): void
    {
        $this->endpoint();
        $plan = $this->plan(PlanStatus::ACTIVE);

        $plan->transitionTo(PlanStatus::CANCELLED, ['action' => 'cancelled']);

        $body = $this->sent(WebhookTopic::SUBSCRIPTION_STATUS_CHANGED);
        $this->assertSame('active', $body['data']['change']['previous_status']);
        $this->assertSame('cancelled', $body['data']['change']['status']);
        $this->assertSame('cancelled', $body['data']['change']['action']);
        $this->assertSame(0, WebhookDelivery::query()->where('topic', WebhookTopic::SUBSCRIPTION_ACTIVATED)->count());
    }

    public function test_a_move_that_rolled_back_announces_nothing(): void
    {
        $this->endpoint();
        $plan = $this->plan(PlanStatus::ACTIVE);

        try {
            DB::transaction(function () use ($plan): void {
                $plan->transitionTo(PlanStatus::PAUSED);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, WebhookDelivery::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_endpoint_that_did_not_subscribe_hears_nothing(): void
    {
        $this->endpoint([WebhookTopic::RECURRING_SUCCEEDED]);
        $plan = $this->plan(PlanStatus::AWAITING_FIRST_PAYMENT);

        $plan->transitionTo(PlanStatus::ACTIVE);

        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_the_settings_test_button_has_a_sample_for_each_new_topic(): void
    {
        foreach (WebhookTopic::SUBSCRIPTION_TOPICS as $topic) {
            $sample = app(SamplePayloads::class)->for($this->shop, $topic, preferReal: false)['payload'];

            $this->assertSame($topic, $sample['topic']);
            $this->assertTrue($sample['test']);
            $this->assertArrayHasKey('change', $sample['data']);
            $this->assertArrayHasKey('client_reference', $sample['data']['subscription']);
        }
    }

    public function test_the_reference_is_kept_out_of_anything_but_a_safe_alphabet(): void
    {
        $plan = $this->plan(PlanStatus::ACTIVE, ['nested' => 'array']);

        $data = app(WebhookPayload::class)->fromPlanStatus(['plan_id' => $plan->id, 'from' => 'paused', 'to' => 'active']);

        $this->assertNull($data['subscription']['client_reference']);
    }

    // === Fixtures ===

    /** @param  list<string>  $topics */
    private function endpoint(array $topics = WebhookTopic::ALL): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'name' => 'Agent',
            'url' => self::URL,
            'secret' => 'whsec_test',
            'topics' => $topics,
            'is_active' => true,
        ]);
    }

    private function plan(PlanStatus $status, mixed $reference = null): InstallmentPlan
    {
        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::RECURRING->value,
            'customer_name' => 'Dana Levi',
            'customer_email' => 'dana@example.com',
            'installment_amount' => 99,
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'currency' => 'USD',
            'next_charge_at' => now()->addMonth(),
            'meta' => array_filter(['item_title' => 'Starter plan', WebhookPayload::META_CLIENT_REFERENCE => $reference]),
        ]);
        $plan->forceFill(['status' => $status->value])->save();

        return $plan;
    }

    /** @return array<string, mixed> the body sent for $topic */
    private function sent(string $topic): array
    {
        foreach (Http::recorded() as [$request]) {
            $body = json_decode($request->body(), true);
            if (($body['topic'] ?? null) === $topic) {
                return $body;
            }
        }

        $this->fail("No webhook was sent for {$topic}.");
    }
}
