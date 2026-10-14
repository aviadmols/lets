<?php

namespace Tests\Feature\Webhooks;

use App\Domain\Billing\Ledger;
use App\Domain\OutboundWebhooks\Models\WebhookDelivery;
use App\Domain\OutboundWebhooks\Models\WebhookEndpoint;
use App\Domain\OutboundWebhooks\WebhookTopic;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * WooCommerce: EVERY order the site takes reaches the merchant's `order.paid`
 * webhook — reported by the plugin once, with its lines and addresses — and the
 * ledger does not announce the same PayPlus order a second time.
 */
final class WooOrderWebhookTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SETTINGS_PATH = '/api/woocommerce/webhook-settings';

    private const REPORT_PATH = '/api/woocommerce/orders/webhook';

    private const URL = 'https://hooks.example.com/woo';

    private const PLUGIN = 'plugins/lets-payplus-woocommerce/includes/class-lets-order-webhooks.php';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([self::URL => Http::response('ok', 200)]);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_plugin_is_told_whether_anyone_listens(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('listen.example.com');

        $this->assertFalse($this->signed('GET', $key, $secret, self::SETTINGS_PATH)->assertOk()->json('settings.enabled'));

        $this->endpoint($shop, [WebhookTopic::RECURRING_FAILED]);
        $this->assertFalse($this->signed('GET', $key, $secret, self::SETTINGS_PATH)->json('settings.enabled'));

        $this->endpoint($shop, [WebhookTopic::ORDER_PAID]);
        $settings = $this->signed('GET', $key, $secret, self::SETTINGS_PATH)->json('settings');
        $this->assertTrue($settings['enabled']);
        $this->assertSame(['processing', 'completed'], $settings['statuses']);
    }

    public function test_any_order_is_sent_with_its_lines_and_addresses_once(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('every.example.com');
        $this->endpoint($shop, [WebhookTopic::ORDER_PAID]);

        $this->signed('POST', $key, $secret, self::REPORT_PATH, $this->order('every.example.com', 'processing'))
            ->assertOk()->assertJson(['queued' => true]);
        // The same order again (a status flap, a re-sent report) is the same event.
        $this->signed('POST', $key, $secret, self::REPORT_PATH, $this->order('every.example.com', 'completed'))
            ->assertOk();

        Http::assertSentCount(1);
        $body = json_decode(Http::recorded()[0][0]->body(), true);

        $this->assertSame(WebhookTopic::ORDER_PAID, $body['topic']);
        $order = $body['data']['order'];
        $this->assertSame('5501', $order['id']);
        $this->assertSame('bacs', $order['payment_method']);
        $this->assertSame(249.9, $order['total']);
        $this->assertSame('Tel Aviv', $order['shipping']['city']);
        $this->assertSame('Coffee 1kg', $order['items'][0]['name']);
        $this->assertSame(2, $order['items'][0]['quantity']);
        $this->assertSame(['WELCOME10'], $order['coupons']);
        $this->assertSame('dana@example.com', $body['data']['customer']['email']);
        $this->assertSame('Dana Levi', $body['data']['customer']['name']);
        $this->assertSame(WebhookDelivery::STATUS_SUCCEEDED, Tenant::run($shop, fn () => WebhookDelivery::query()->sole()->status));
    }

    public function test_a_report_from_another_site_is_refused(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('real.example.com');
        $this->endpoint($shop, [WebhookTopic::ORDER_PAID]);

        $this->signed('POST', $key, $secret, self::REPORT_PATH, $this->order('staging.example.com'))
            ->assertStatus(422)->assertJson(['error' => 'site_mismatch']);

        Http::assertNothingSent();
    }

    public function test_the_ledger_does_not_announce_a_woocommerce_order_a_second_time(): void
    {
        [$shop] = $this->connectedShop('ledger.example.com');
        $this->endpoint($shop, WebhookTopic::ALL);

        Tenant::run($shop, function () use ($shop): void {
            $row = Ledger::open((int) $shop->id, PaymentLedger::CONTEXT_GATEWAY, 'gw-1', 99.0);
            Ledger::transition($row, LedgerStatus::SUCCEEDED);

            $cycle = Ledger::open((int) $shop->id, PaymentLedger::CONTEXT_RECURRING, 'cy-1', 49.0);
            Ledger::transition($cycle, LedgerStatus::SUCCEEDED);
        });

        // Only the cycle — the order itself arrives from the plugin.
        Http::assertSentCount(1);
        $this->assertSame(WebhookTopic::RECURRING_SUCCEEDED, json_decode(Http::recorded()[0][0]->body(), true)['topic']);
    }

    public function test_the_plugin_hook_can_never_fatal_an_order(): void
    {
        $source = (string) file_get_contents(base_path(self::PLUGIN));

        // It runs inside woocommerce_order_status_changed: a throw there fails the
        // whole request (the order save, the thank-you page), not just the report.
        $this->assertMatchesRegularExpression('/function lets_payplus_webhook_settings\(\)\s*\{\s*try\s*\{/s', $source);
        $this->assertMatchesRegularExpression('/function lets_payplus_webhook_on_status_changed\([^)]*\)\s*\{\s*try\s*\{/s', $source);
        $this->assertSame(2, substr_count($source, 'catch (\Throwable $e)'));
        // Once per order: the stamp is checked before any report.
        $this->assertStringContainsString('LETS_PAYPLUS_WEBHOOK_REPORTED_META', $source);
    }

    /** @return array{0: Shop, 1: string, 2: string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $data = (array) json_decode((string) base64_decode(strtr($result['connection_token'], '-_', '+/')), true);

        return [$result['shop']->fresh(), (string) $data['k'], (string) $data['s']];
    }

    /** @param list<string> $topics */
    private function endpoint(Shop $shop, array $topics): void
    {
        Tenant::run($shop, fn () => WebhookEndpoint::create([
            'name' => 'Make', 'url' => self::URL, 'secret' => 's', 'topics' => $topics, 'is_active' => true,
        ]));
    }

    /** @return array<string, mixed> */
    private function order(string $site, string $status = 'processing'): array
    {
        $address = [
            'first_name' => 'Dana', 'last_name' => 'Levi', 'address_1' => '1 Herzl St', 'city' => 'Tel Aviv',
            'postcode' => '6100000', 'country' => 'IL', 'email' => 'dana@example.com', 'phone' => '0500000000',
        ];

        return [
            'order_id' => '5501',
            'order_number' => '5501',
            'site_url' => 'https://'.$site,
            'status' => $status,
            'role' => 'checkout',
            'currency' => 'ILS',
            'total' => 249.90,
            'subtotal' => 239.90,
            'shipping_total' => 10,
            'payment_method' => 'bacs',
            'payment_method_title' => 'Bank transfer',
            'customer_id' => '17',
            'date_paid' => '2026-10-14T10:00:00+03:00',
            'billing' => $address,
            'shipping' => $address,
            'coupons' => ['WELCOME10'],
            'items' => [[
                'product_id' => '88', 'variation_id' => '0', 'sku' => 'COF-1', 'name' => 'Coffee 1kg',
                'quantity' => 2, 'subtotal' => 239.90, 'total' => 239.90, 'after_sell' => false,
            ]],
        ];
    }

    private function signed(string $method, string $apiKey, string $apiSecret, string $path, array $body = []): TestResponse
    {
        $json = $method === 'GET' ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.$method.$path.$json, $apiSecret, true));

        return $this->call($method, $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey,
            'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $json);
    }
}
