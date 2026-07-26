<?php

namespace App\Domain\Upsell\PostPurchase;

use App\Services\Shopify\ShopifyApps;

/**
 * Verifies the token Shopify hands the NATIVE post-purchase extension.
 *
 * Shopify signs that token (HS256) with the app's own shared secret and puts the
 * whole purchase inside it: the shop, the reference id of the checkout, the line
 * items, and the customer. That is exactly why it is the only thing we trust —
 * the extension runs in a sandboxed worker on an origin we do not control, so
 * NOTHING it sends about shop, price, or products may be believed. Everything
 * this app acts on is read from the VERIFIED claims.
 *
 * Deployment note: with two Partner apps on one deployment (public + custom) the
 * token could come from either, so each configured secret is tried; the one that
 * verifies also identifies the app whose secret must later SIGN the changeset.
 *
 * Fail closed: any bad segment, bad signature, or expired token returns null.
 */
final class PostPurchaseTokenVerifier
{
    // === CONSTANTS ===
    private const ALG = 'HS256';
    private const LEEWAY_SECONDS = 30;

    /**
     * @return array{claims: array<string, mixed>, app_key: string}|null
     */
    public function verify(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeSegment($encodedHeader);
        $claims = $this->decodeSegment($encodedPayload);
        if ($header === null || $claims === null) {
            return null;
        }

        // Algorithm pinned — never let the token pick a weaker one.
        if (($header['alg'] ?? '') !== self::ALG) {
            return null;
        }

        foreach (ShopifyApps::keys() as $appKey) {
            $secret = ShopifyApps::credentials($appKey)['api_secret'];
            $expected = $this->base64UrlEncode(
                hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $secret, true)
            );

            if (! hash_equals($expected, $encodedSignature)) {
                continue;
            }

            $now = time();
            if (isset($claims['exp']) && $now >= ((int) $claims['exp'] + self::LEEWAY_SECONDS)) {
                return null;
            }
            if (isset($claims['nbf']) && $now < ((int) $claims['nbf'] - self::LEEWAY_SECONDS)) {
                return null;
            }

            return ['claims' => $claims, 'app_key' => $appKey];
        }

        return null;
    }

    /**
     * The *.myshopify.com domain from the verified claims. Shopify nests it under
     * input_data.shop.domain; the top-level fallbacks cover shapes seen across
     * API versions. Empty string when absent (the caller then refuses).
     *
     * @param  array<string, mixed>  $claims
     */
    public function shopDomain(array $claims): string
    {
        $domain = (string) (
            data_get($claims, 'input_data.shop.domain')
            ?? data_get($claims, 'shop.domain')
            ?? data_get($claims, 'dest')
            ?? ''
        );

        $host = parse_url($domain, PHP_URL_HOST);

        return strtolower((string) ($host ?: $domain));
    }

    /**
     * The checkout's reference id — the changeset's `sub`, and the identity a
     * later acceptance is deduped by.
     *
     * @param  array<string, mixed>  $claims
     */
    public function referenceId(array $claims): string
    {
        return (string) (data_get($claims, 'input_data.initialPurchase.referenceId') ?? '');
    }

    /** @return array<string, mixed>|null */
    private function decodeSegment(string $segment): ?array
    {
        $json = $this->base64UrlDecode($segment);
        if ($json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
