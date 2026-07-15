<?php

namespace Tests\Feature\WooCommerce;

use App\Models\Shop;
use App\Services\WooCommerce\WooPluginNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * W16 — the SaaS→plugin notification channel. WooPluginNotifier POSTs a signed event to the
 * plugin's /notify route (HMAC over ts+POST+path+body with the shop's wc_webhook_secret), so the
 * plugin can log it + email the site admin. The plugin verifies the SAME signature.
 */
final class WooPluginNotifierTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/wp-json/lets-payplus/v1/notify';

    public function test_payment_failed_posts_a_validly_signed_event_to_the_plugin(): void
    {
        Http::fake(['*'.self::PATH => Http::response(['ok' => true], 200)]);

        $shop = Shop::create(['name' => 'WC', 'status' => Shop::STATUS_ACTIVE]);
        $shop->forceFill([
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'woocommerce_domain' => 'store.example.com',
            'woocommerce_credentials' => [
                'base_url' => 'https://store.example.com',
                'consumer_key' => 'ck', 'consumer_secret' => 'cs',
                'wc_webhook_secret' => 'whsecret-123',
            ],
        ])->save();

        app(WooPluginNotifier::class)->paymentFailed($shop->fresh(), '2487', '999', 'declined');

        Http::assertSent(function (HttpRequest $req): bool {
            if (! str_ends_with($req->url(), self::PATH) || $req->method() !== 'POST') {
                return false;
            }
            $ts = $req->header('X-LETS-Timestamp')[0] ?? '';
            $sig = $req->header('X-LETS-Signature')[0] ?? '';
            $body = $req->body();

            // The plugin recomputes exactly this.
            $expected = base64_encode(hash_hmac('sha256', $ts.'POST'.self::PATH.$body, 'whsecret-123', true));

            $json = json_decode($body, true);

            return hash_equals($expected, $sig)
                && ($json['event'] ?? null) === 'payment_failed'
                && ($json['order_id'] ?? null) === '2487'
                && ($json['status_code'] ?? null) === '999';
        });
    }

    public function test_it_no_ops_without_a_webhook_secret(): void
    {
        Http::fake();

        $shop = Shop::create(['name' => 'WC2', 'status' => Shop::STATUS_ACTIVE]);
        $shop->forceFill([
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
            'woocommerce_credentials' => ['base_url' => 'https://x.example.com'], // no secret
        ])->save();

        app(WooPluginNotifier::class)->paymentFailed($shop->fresh(), '1', '999');

        Http::assertNothingSent();
    }
}
