<?php

namespace App\Services\WooCommerce;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;

/**
 * Live "is this WooCommerce store actually connected?" check for the platform admin's
 * shop view. Answers two questions the owner asked:
 *
 *   1. Does the connection WORK?  → call the store's WooCommerce REST API with the saved
 *      consumer key/secret (WooCommerceClient::ping). 200 = reachable + creds valid;
 *      401/403 = keys rejected; transport failure = unreachable.
 *   2. Is the PLUGIN installed with the CORRECT token?  → probe the LETS plugin's own
 *      status route (GET {base_url}/wp-json/lets-payplus/v1/status) and compare the token
 *      fingerprint it reports (sha256 of its stored api_key — NOT a secret; it is exactly
 *      what we keep as lets_api_key_hash) against this shop's lets_api_key_hash. Match =
 *      the plugin holds the token we minted; mismatch = an old/foreign token → re-connect.
 *
 * Read-only and fail-soft: every branch returns a structured verdict (level + lines), so
 * the action just renders it. Never throws on a dead/missing store.
 */
final class WooConnectionTester
{
    // === CONSTANTS ===
    private const PLUGIN_STATUS_PATH = '/wp-json/lets-payplus/v1/status';
    private const PLUGIN_PROBE_TIMEOUT = 12;

    /**
     * @return array{ok: bool, level: string, lines: array<int, string>}
     *         level ∈ success|warning|danger; lines are pre-translated detail bullets.
     */
    public function test(Shop $shop): array
    {
        $lines = [];
        $hasCreds = $shop->hasWooConnection();

        // --- 1. WooCommerce REST (only meaningful once the handshake stored ck/cs) ---
        $wcOk = false;
        if (! $hasCreds) {
            $lines[] = __('platform.woo.test.no_credentials');
        } else {
            $wc = WooClientFactory::for($shop)->ping();
            $wcOk = $wc['ok'];

            $lines[] = match (true) {
                $wc['ok'] => __('platform.woo.test.wc_ok'),
                ($wc['reason'] ?? '') === 'unauthorized' => __('platform.woo.test.wc_unauthorized'),
                ($wc['reason'] ?? '') === 'unreachable' => __('platform.woo.test.wc_unreachable'),
                default => __('platform.woo.test.wc_error', ['status' => $wc['status'] ?? 0]),
            };
        }

        // --- 2. LETS plugin token fingerprint (best-effort; older plugins lack the route) ---
        $plugin = $this->probePlugin($shop);
        $pluginMatches = false;
        switch ($plugin['state']) {
            case 'match':
                $pluginMatches = true;
                $lines[] = __('platform.woo.test.plugin_ok', ['version' => $plugin['version']]);
                break;
            case 'mismatch':
                $lines[] = __('platform.woo.test.plugin_mismatch');
                break;
            case 'not_connected':
                $lines[] = __('platform.woo.test.plugin_not_connected', ['version' => $plugin['version']]);
                break;
            case 'not_found':
                $lines[] = __('platform.woo.test.plugin_not_found');
                break;
            default: // unreachable
                $lines[] = __('platform.woo.test.plugin_unreachable');
        }

        // --- Verdict ---
        $level = match (true) {
            $wcOk && $pluginMatches => 'success',
            ! $hasCreds || ($wcOk && $plugin['state'] === 'not_found') => 'warning',
            $wcOk && in_array($plugin['state'], ['unreachable', 'not_connected'], true) => 'warning',
            default => 'danger',
        };

        return ['ok' => $wcOk && $pluginMatches, 'level' => $level, 'lines' => $lines];
    }

    /**
     * GET the LETS plugin's status route and classify it against this shop's stored key
     * hash. Returns ['state' => match|mismatch|not_connected|not_found|unreachable, 'version' => string].
     *
     * @return array{state: string, version: string}
     */
    private function probePlugin(Shop $shop): array
    {
        $base = $shop->wooCredential('base_url') ?: ($shop->woocommerce_domain ? 'https://'.$shop->woocommerce_domain : null);
        if ($base === null) {
            return ['state' => 'not_found', 'version' => ''];
        }

        try {
            $response = Http::timeout(self::PLUGIN_PROBE_TIMEOUT)
                ->acceptJson()
                ->get(rtrim($base, '/').self::PLUGIN_STATUS_PATH);
        } catch (\Throwable) {
            return ['state' => 'unreachable', 'version' => ''];
        }

        if ($response->status() === 404) {
            return ['state' => 'not_found', 'version' => ''];
        }
        if (! $response->successful()) {
            return ['state' => 'unreachable', 'version' => ''];
        }

        $body = (array) $response->json();
        $version = (string) ($body['plugin_version'] ?? '');
        $reportedHash = (string) ($body['key_hash'] ?? '');
        $expectedHash = (string) ($shop->lets_api_key_hash ?? '');

        if ($reportedHash === '' || $expectedHash === '') {
            return ['state' => 'not_connected', 'version' => $version];
        }

        // Constant-time compare — a token fingerprint, but treat it like one anyway.
        return ['state' => hash_equals($expectedHash, $reportedHash) ? 'match' : 'mismatch', 'version' => $version];
    }
}
