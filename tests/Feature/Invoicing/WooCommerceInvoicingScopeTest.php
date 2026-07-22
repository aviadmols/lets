<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Models\MerchantInvoicingSettings;
use App\Models\Shop;
use App\Services\WooCommerce\Orders\WooCommerceOrderStrategy;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The `all_orders` scope endpoint: the seam that lets a WooCommerce merchant invoice
 * EVERY order their site receives, not just LETS ones.
 *
 * Every assertion guards against issuing a tax document the merchant did not ask for.
 * The plugin caches its scope, so its copy can be stale — which is exactly why the
 * scope, the status list, and the plan-order check are all re-evaluated server-side.
 */
final class WooCommerceInvoicingScopeTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const SETTINGS_PATH = '/api/woocommerce/invoicing-settings';
    private const ISSUE_PATH = '/api/woocommerce/orders/issue-document';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_plugin_is_told_the_merchants_scope_and_statuses(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('scope.example.com');
        $this->enableInvoicing($shop, MerchantInvoicingSettings::SCOPE_ALL_ORDERS, ['completed']);

        $settings = $this->signed('GET', $key, $secret, self::SETTINGS_PATH)
            ->assertOk()
            ->json('settings');

        $this->assertTrue($settings['enabled']);
        $this->assertSame(MerchantInvoicingSettings::SCOPE_ALL_ORDERS, $settings['scope']);
        $this->assertSame(['completed'], $settings['trigger_statuses']);
    }

    public function test_a_shop_without_credentials_is_reported_as_not_enabled(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('nokeys.example.com');

        // Module flag on, but no API key — telling the plugin "enabled" here would make
        // it POST every order into a pipeline that can only ever record failures.
        MerchantInvoicingSettings::forShop((int) $shop->getKey())
            ->forceFill(['enabled' => true, 'scope' => MerchantInvoicingSettings::SCOPE_ALL_ORDERS])
            ->save();

        $this->assertFalse(
            $this->signed('GET', $key, $secret, self::SETTINGS_PATH)->assertOk()->json('settings.enabled')
        );
    }

    public function test_a_plans_only_shop_does_not_queue_a_document_for_a_plain_order(): void
    {
        Queue::fake();
        [$shop, $key, $secret] = $this->connectedShop('plansonly.example.com');
        $this->enableInvoicing($shop, MerchantInvoicingSettings::SCOPE_PLANS_ONLY);

        $response = $this->signed('POST', $key, $secret, self::ISSUE_PATH, $this->order())->assertOk();

        $this->assertFalse($response->json('queued'));
        $this->assertSame('out_of_scope', $response->json('reason'));
        Queue::assertNothingPushed();
    }

    public function test_an_all_orders_shop_queues_the_document(): void
    {
        Queue::fake();
        [$shop, $key, $secret] = $this->connectedShop('allorders.example.com');
        $this->enableInvoicing($shop, MerchantInvoicingSettings::SCOPE_ALL_ORDERS);

        $this->signed('POST', $key, $secret, self::ISSUE_PATH, $this->order())
            ->assertOk()
            ->assertJson(['queued' => true]);

        Queue::assertPushed(IssueDocumentJob::class, fn (IssueDocumentJob $job): bool => $job->shopId === (int) $shop->getKey()
            && $job->order['order_id'] === '5501');
    }

    public function test_a_status_the_merchant_did_not_pick_is_refused(): void
    {
        Queue::fake();
        [$shop, $key, $secret] = $this->connectedShop('status.example.com');
        $this->enableInvoicing($shop, MerchantInvoicingSettings::SCOPE_ALL_ORDERS, ['completed']);

        // The plugin's cached settings still said "processing" — the server has the
        // merchant's current choice and refuses. A tax document for an order the
        // merchant does not consider paid is the failure mode this prevents.
        $response = $this->signed('POST', $key, $secret, self::ISSUE_PATH, $this->order(status: 'processing'))
            ->assertOk();

        $this->assertSame('status_not_selected', $response->json('reason'));
        Queue::assertNothingPushed();
    }

    public function test_an_order_belonging_to_a_lets_plan_is_refused(): void
    {
        Queue::fake();
        [$shop, $key, $secret] = $this->connectedShop('planorder.example.com');
        $this->enableInvoicing($shop, MerchantInvoicingSettings::SCOPE_ALL_ORDERS);

        // This order is already invoiced through the plan pipeline. Accepting it here
        // would DOUBLE-DECLARE the same income.
        $body = $this->order();
        $body[WooCommerceOrderStrategy::META_PLAN_PUBLIC_ID] = '01JPLAN';

        $response = $this->signed('POST', $key, $secret, self::ISSUE_PATH, $body)->assertOk();

        $this->assertSame('plan_order', $response->json('reason'));
        Queue::assertNothingPushed();
    }

    public function test_an_order_with_no_money_is_rejected(): void
    {
        Queue::fake();
        [$shop, $key, $secret] = $this->connectedShop('zero.example.com');
        $this->enableInvoicing($shop, MerchantInvoicingSettings::SCOPE_ALL_ORDERS);

        $this->signed('POST', $key, $secret, self::ISSUE_PATH, $this->order(total: 0))
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_an_unsigned_request_cannot_reach_the_endpoint(): void
    {
        Queue::fake();
        $this->connectedShop('unsigned.example.com');

        $this->postJson(self::ISSUE_PATH, $this->order())->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_a_shopify_shop_cannot_be_put_into_all_orders_scope(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'scope.myshopify.com',
            'name' => 'Shopify scope',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);

        // `all_orders` needs a storefront that reports its own orders; only the
        // WooCommerce plugin does. The admin page forces a Shopify shop back to
        // plans_only server-side so a tampered form cannot enable a dead scope.
        $page = new \App\Filament\Pages\ManageInvoicing();
        $resolve = new \ReflectionMethod($page, 'resolveScope');

        $this->assertSame(
            MerchantInvoicingSettings::SCOPE_PLANS_ONLY,
            $resolve->invoke($page, $shop, MerchantInvoicingSettings::SCOPE_ALL_ORDERS),
        );
    }

    // === Helpers ===

    /** @return array{0:Shop,1:string,2:string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $data = (array) json_decode(
            (string) base64_decode(strtr($result['connection_token'], '-_', '+/')),
            true,
        );

        return [$result['shop']->fresh(), (string) $data['k'], (string) $data['s']];
    }

    /** @param list<string>|null $statuses */
    private function enableInvoicing(Shop $shop, string $scope, ?array $statuses = null): void
    {
        $shop->invoicing_credentials = [
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'api_key_id' => 'key-id',
            'api_secret' => 'key-secret',
            'environment' => Shop::INVOICING_ENV_SANDBOX,
        ];
        $shop->save();

        MerchantInvoicingSettings::forShop((int) $shop->getKey())->forceFill([
            'enabled' => true,
            'scope' => $scope,
            'trigger_statuses' => $statuses ?? MerchantInvoicingSettings::DEFAULT_TRIGGER_STATUSES,
        ])->save();
    }

    /** @return array<string, mixed> */
    private function order(float $total = 249.90, string $status = 'completed'): array
    {
        return [
            'order_id' => '5501',
            'order_number' => '#5501',
            'status' => $status,
            'total' => $total,
            'currency' => 'ILS',
            'customer_name' => 'Dana Buyer',
            'customer_email' => 'buyer@example.com',
            'payment_gateway' => 'bacs',
            'lines' => [
                ['description' => 'Mug', 'unit_price' => 124.95, 'quantity' => 2, 'catalog_number' => 'MUG-1'],
            ],
        ];
    }

    /** @param array<string, mixed> $body */
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
