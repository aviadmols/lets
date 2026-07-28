<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Models\IssuedDocument;
use App\Models\MerchantInvoicingSettings;
use App\Models\Shop;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A gift is given, not sold — so it must never mint a tax document.
 *
 * The trap this closes: a gift order lands in WooCommerce as `processing`, which
 * is a status the merchant selected as "invoice this". The store's own hook would
 * report it, and a document for it would declare income the merchant never
 * received — a VAT correction, not a bug fix.
 *
 * The plugin skips gift orders too, but only updated builds do. This wall holds
 * regardless of what the store is running or whether its meta survived.
 */
final class GiftOrderInvoicingWallTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const PATH = '/api/woocommerce/orders/issue-document';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_reported_gift_order_mints_no_document(): void
    {
        Queue::fake();
        [$shop, $key, $secret] = $this->connectedShop();
        $this->enableAllOrdersInvoicing($shop);
        $this->giftOrder($shop, '7700');

        $response = $this->signedPost($key, $secret, self::PATH, [
            'order_id' => '7700',
            'status' => 'processing',
            'total' => 73.00,   // the gift's VALUE, which a naive reader would invoice
        ]);

        $response->assertOk()
            ->assertJsonPath('queued', false)
            ->assertJsonPath('reason', 'gift_order');

        Queue::assertNotPushed(IssueDocumentJob::class);

        Tenant::run($shop, function (): void {
            $this->assertSame(0, IssuedDocument::query()->count());
        });
    }

    public function test_a_real_order_is_still_invoiced(): void
    {
        Queue::fake();
        [$shop, $key, $secret] = $this->connectedShop();
        $this->enableAllOrdersInvoicing($shop);

        // No gift row for this order — the wall must not swallow real sales.
        $this->signedPost($key, $secret, self::PATH, [
            'order_id' => '7701',
            'status' => 'processing',
            'total' => 73.00,
        ])->assertOk()->assertJsonPath('queued', true);

        Queue::assertPushed(IssueDocumentJob::class);
    }

    public function test_another_shops_gift_row_does_not_shield_this_shops_order(): void
    {
        Queue::fake();
        [$shop, $key, $secret] = $this->connectedShop();
        $other = $this->connectedShop('gift-wall-other.example.com')[0];
        $this->enableAllOrdersInvoicing($shop);

        // Shop B gifted order 7702; shop A's own order 7702 is a real sale.
        $this->giftOrder($other, '7702');

        $this->signedPost($key, $secret, self::PATH, [
            'order_id' => '7702',
            'status' => 'processing',
            'total' => 50.00,
        ])->assertOk()->assertJsonPath('queued', true);

        Queue::assertPushed(IssueDocumentJob::class);
    }

    // === Fixtures ===

    /** @return array{0: Shop, 1: string, 2: string} */
    private function connectedShop(string $domain = 'gift-wall.example.com'): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];

        $json = (string) base64_decode(strtr($result['connection_token'], '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [$shop->fresh(), (string) $data['k'], (string) $data['s']];
    }

    private function enableAllOrdersInvoicing(Shop $shop): void
    {
        $shop->invoicing_credentials = [
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'api_key_id' => 'key-id',
            'api_secret' => 'key-secret',
            'environment' => Shop::INVOICING_ENV_SANDBOX,
        ];
        $shop->save();

        MerchantInvoicingSettings::forShop((int) $shop->getKey())
            ->forceFill(['enabled' => true, 'scope' => 'all_orders'])
            ->save();
    }

    private function giftOrder(Shop $shop, string $orderId): void
    {
        Tenant::run($shop, function () use ($shop, $orderId): void {
            $campaign = new GiftCampaign;
            $campaign->forceFill([
                'shop_id' => $shop->getKey(),
                'title' => 'Thanks',
                'min_cycles' => 3,
                'currency' => 'ILS',
                'status' => GiftCampaign::STATUS_COMPLETED,
            ])->save();

            $recipient = new GiftRecipient;
            $recipient->forceFill([
                'shop_id' => $shop->getKey(),
                'gift_campaign_id' => $campaign->getKey(),
                'source_type' => GiftRecipient::SOURCE_PLAN,
                'source_id' => 1,
                'status' => GiftRecipient::STATUS_CREATED,
                'external_order_id' => $orderId,
                'currency' => 'ILS',
            ])->save();
        });
    }

    /** @param array<string, mixed> $body */
    private function signedPost(string $apiKey, string $apiSecret, string $path, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.$path.$json, $apiSecret, true));

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey, 'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }
}
