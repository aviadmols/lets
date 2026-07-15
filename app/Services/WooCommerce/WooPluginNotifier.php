<?php

namespace App\Services\WooCommerce;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Signed SaaS → plugin notification (W16). The ONLY server-to-server call FROM LETS TO the
 * WordPress plugin: it POSTs a small event to {base_url}/wp-json/lets-payplus/v1/notify, signed
 * with the shop's wc_webhook_secret (the same secret the install handshake minted + returned to
 * the plugin). The plugin verifies the HMAC, logs the event, and emails the site admin.
 *
 * The signing scheme MIRRORS the plugin's own lets_payplus_signed_post (HMAC-SHA256 over
 * ts + METHOD + path + rawBody), just in the opposite direction with the wc_webhook_secret as
 * the key — so both sides share one convention.
 *
 * Fail-soft by contract: every caller is a payment-callback path where the money outcome is
 * already decided. A notification failure is logged and swallowed; it never changes an order
 * or a response.
 */
final class WooPluginNotifier
{
    // === CONSTANTS ===
    private const PATH = '/wp-json/lets-payplus/v1/notify';
    private const TIMEOUT_SECONDS = 8;

    /** Tell the plugin a gateway payment FAILED, so it can log it + email the admin. */
    public function paymentFailed(Shop $shop, string $orderId, string $statusCode, string $reason = ''): void
    {
        $this->send($shop, [
            'event' => 'payment_failed',
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'reason' => $reason,
        ]);
    }

    /**
     * Sign + POST the event to the plugin. No-op (logged) when the store isn't reachable or
     * has no webhook secret — never throws.
     *
     * @param array<string, mixed> $event
     */
    private function send(Shop $shop, array $event): void
    {
        $cfg = $shop->wooConfig();
        $baseUrl = (string) ($cfg['base_url'] ?? '');
        $secret = (string) ($cfg['wc_webhook_secret'] ?? '');

        if ($baseUrl === '' || $secret === '') {
            Log::info('woocommerce.notify.skipped_no_channel', ['shop_id' => $shop->getKey()]);

            return;
        }

        $body = (string) json_encode($event, JSON_UNESCAPED_SLASHES);
        $ts = (string) now()->timestamp;
        $signature = base64_encode(hash_hmac('sha256', $ts.'POST'.self::PATH.$body, $secret, true));

        try {
            Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-LETS-Timestamp' => $ts,
                'X-LETS-Signature' => $signature,
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->withBody($body, 'application/json')
                ->post(rtrim($baseUrl, '/').self::PATH);
        } catch (\Throwable $e) {
            Log::warning('woocommerce.notify.transport_error', [
                'shop_id' => $shop->getKey(), 'error' => $e->getMessage(),
            ]);
        }
    }
}
