<?php

namespace Tests\Feature\Webhooks;

use App\Domain\OutboundWebhooks\Models\WebhookDelivery;
use App\Domain\OutboundWebhooks\Models\WebhookEndpoint;
use App\Domain\OutboundWebhooks\SamplePayloads;
use App\Domain\OutboundWebhooks\WebhookTopic;
use App\Filament\Pages\ManageWebhooks;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → Webhooks: add a URL (https + public only), generate test JSONs, and
 * send a test that lands in the delivery log flagged as a test.
 */
final class ManageWebhooksPageTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const URL = 'https://hooks.example.com/lets';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create(['shopify_domain' => 'hookpage.myshopify.com', 'name' => 'Hook page', 'status' => Shop::STATUS_ACTIVE]);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_page_renders(): void
    {
        Livewire::test(ManageWebhooks::class)
            ->assertOk()
            ->assertSee(__('webhooks.generator.title'))
            ->assertSee(__('webhooks.log.title'));
    }

    public function test_an_endpoint_is_created_with_a_generated_secret(): void
    {
        Livewire::test(ManageWebhooks::class)
            ->callTableAction('create', data: [
                'name' => 'Make',
                'url' => self::URL,
                'topics' => [WebhookTopic::ORDER_PAID, WebhookTopic::RECURRING_FAILED],
                'is_active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $endpoint = WebhookEndpoint::query()->sole();
        $this->assertSame($this->shop->id, $endpoint->shop_id);
        $this->assertSame([WebhookTopic::ORDER_PAID, WebhookTopic::RECURRING_FAILED], $endpoint->subscribedTopics());
        $this->assertStringStartsWith(WebhookEndpoint::SECRET_PREFIX, $endpoint->secret);
    }

    public function test_a_plain_http_or_internal_url_is_refused(): void
    {
        foreach (['http://hooks.example.com/lets', 'https://127.0.0.1/hook', 'https://hooks.example.com:8443/x'] as $url) {
            Livewire::test(ManageWebhooks::class)
                ->callTableAction('create', data: ['name' => 'Bad', 'url' => $url, 'topics' => WebhookTopic::ALL, 'is_active' => true]);
        }

        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_the_generator_builds_a_test_json_for_every_topic(): void
    {
        foreach (WebhookTopic::ALL as $topic) {
            $component = Livewire::test(ManageWebhooks::class)
                ->set('sampleTopic', $topic)
                ->set('sampleOrigin', SamplePayloads::ORIGIN_REAL)
                ->call('generateSample');

            $json = json_decode((string) $component->get('sampleJson'), true);

            $this->assertSame($topic, $json['topic']);
            $this->assertTrue($json['test']);
            $this->assertSame(['charge', 'order', 'customer', 'subscription'], array_keys($json['data']));
            // A fresh shop has no history: the example stands in.
            $this->assertSame(SamplePayloads::ORIGIN_EXAMPLE, $component->get('sampleBuiltFrom'));
        }
    }

    public function test_the_log_says_per_request_whether_it_was_received(): void
    {
        $endpoint = WebhookEndpoint::create([
            'name' => 'Make', 'url' => self::URL, 'secret' => 's', 'topics' => WebhookTopic::ALL,
        ]);
        $row = fn (string $key, string $status, ?int $http, string $email): WebhookDelivery => WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'topic' => WebhookTopic::ORDER_PAID,
            'event_key' => $key,
            'status' => $status,
            'attempts' => 1,
            'response_status' => $http,
            'payload' => ['data' => ['order' => ['number' => $key], 'customer' => ['email' => $email]]],
        ]);
        $row('1001', WebhookDelivery::STATUS_SUCCEEDED, 200, 'got@example.com');
        $row('1002', WebhookDelivery::STATUS_FAILED, 500, 'lost@example.com');
        $row('1003', WebhookDelivery::STATUS_RETRYING, 503, 'wait@example.com');

        $page = Livewire::test(ManageWebhooks::class)
            ->assertSee(__('webhooks.status.succeeded'))
            ->assertSee(__('webhooks.status.failed'))
            ->assertSee(__('webhooks.log.order', ['number' => '1001']))
            ->assertSee('HTTP 500');

        $this->assertSame(['all' => 3, 'received' => 1, 'not_received' => 2], $page->instance()->logCounts());

        $page->call('setLogFilter', ManageWebhooks::LOG_NOT_RECEIVED)
            ->assertSee('lost@example.com')
            ->assertSee('wait@example.com')
            ->assertDontSee('got@example.com');

        $page->call('setLogFilter', ManageWebhooks::LOG_RECEIVED)
            ->assertSee('got@example.com')
            ->assertDontSee('lost@example.com');
    }

    public function test_send_test_delivers_a_flagged_sample_and_logs_it(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        $endpoint = WebhookEndpoint::create([
            'name' => 'Make', 'url' => self::URL, 'secret' => 's', 'topics' => [WebhookTopic::ORDER_PAID],
        ]);

        Livewire::test(ManageWebhooks::class)
            ->callTableAction('test', $endpoint, data: [
                'topic' => WebhookTopic::RECURRING_FAILED,
                'origin' => SamplePayloads::ORIGIN_EXAMPLE,
            ])
            ->assertSee(__('webhooks.log.test'));

        Http::assertSent(fn ($request): bool => $request->header('X-Lets-Test')[0] === 'true'
            && json_decode($request->body(), true)['topic'] === WebhookTopic::RECURRING_FAILED);

        $delivery = WebhookDelivery::query()->sole();
        $this->assertTrue($delivery->is_test);
        $this->assertSame(WebhookDelivery::STATUS_SUCCEEDED, $delivery->status);
    }
}
