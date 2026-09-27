<?php

namespace App\Services\WooCommerce;

use App\Domain\Brand\SafeSiteFetcher;

/**
 * The gate every server → WooCommerce-store request passes: the REST client,
 * the plugin notifier and the connection tester.
 *
 * The store's host is merchant-steered twice over — the plugin reports
 * `base_url`, and the merchant owns the DNS of their own domain, so a perfectly
 * well-formed name can still answer 10.x or 169.254.169.254. So:
 *
 *   1. the stored value must be a canonical store URL (WooStoreUrl) — no query
 *      or fragment to swallow the fixed `/wp-json/...` suffix, no userinfo, no port;
 *   2. the host goes through SafeSiteFetcher's address walls (the platform's
 *      ONE definition of a public address — not re-invented here);
 *   3. the request is PINNED to the judged address, so the connect cannot
 *      resolve the name a second time to somewhere else.
 *
 * A refusal throws WooStoreRefused — a ConnectionException, so every caller
 * that already treats "store unreachable" as a soft failure handles it unchanged.
 */
final class WooStoreEndpoint
{
    /**
     * @return array{base: string, options: array<string, mixed>} the canonical base URL
     *                                                             and the pinning options to merge into the request
     *
     * @throws WooStoreRefused
     */
    public static function prepare(?string $baseUrl): array
    {
        $base = WooStoreUrl::canonical($baseUrl);
        if ($base === null) {
            throw new WooStoreRefused(SafeSiteFetcher::REASON_INVALID_URL);
        }

        $vetted = app(SafeSiteFetcher::class)->vet($base);
        if ($vetted['reason'] !== null) {
            throw new WooStoreRefused($vetted['reason']);
        }

        return ['base' => $base, 'options' => $vetted['options']];
    }
}
