<?php

namespace App\Services\Shopify;

/**
 * Verifies an App Bridge session token (a short-lived HS256 JWT signed with the
 * app secret). Self-contained — no JWT library dependency — because the algorithm
 * is fixed (HS256) and the claim set is small and well-defined.
 *
 * Returns the decoded, VALIDATED claims, or null on any failure (fail closed).
 * Validated: signature (HS256 over header.payload with SHOPIFY_API_SECRET);
 * aud == SHOPIFY_API_KEY; exp > now; nbf <= now (small leeway); iss & dest share
 * the same shop host. The caller derives the shop from the dest claim.
 *
 * Two token SHAPES reach here and both are legitimate: the embedded admin's App
 * Bridge sends iss/dest as full https://{shop}/admin URLs, while a checkout or
 * customer-account UI extension sends the bare {shop}.myshopify.com host. See
 * host() — assuming the first shape is what silently rejected every extension.
 *
 * Session tokens are per-request and ~1 min lived — never persisted, never used
 * as the API token. They only authenticate the embedded-admin REQUEST and tell us
 * which shop is looking; the offline token (from OAuth) does the API work.
 */
final class SessionTokenVerifier
{
    // === CONSTANTS ===
    private const ALG = 'HS256';
    private const LEEWAY_SECONDS = 5;

    /**
     * @return array<string, mixed>|null  validated claims, or null on failure
     */
    public function verify(string $jwt, string $secret, string $apiKey): ?array
    {
        if ($secret === '' || $apiKey === '') {
            return null;
        }

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

        // Algorithm pinned — never trust the token's alg to downgrade.
        if (($header['alg'] ?? '') !== self::ALG) {
            return null;
        }

        // Signature.
        $expected = $this->base64UrlEncode(
            hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $secret, true)
        );
        if (! hash_equals($expected, $encodedSignature)) {
            return null;
        }

        // Claims.
        $now = time();
        if ((string) ($claims['aud'] ?? '') !== $apiKey) {
            return null;
        }
        if (isset($claims['exp']) && $now >= ((int) $claims['exp'] + self::LEEWAY_SECONDS)) {
            return null;
        }
        if (isset($claims['nbf']) && $now < ((int) $claims['nbf'] - self::LEEWAY_SECONDS)) {
            return null;
        }

        // iss and dest must name the same shop. Both must still resolve to a valid
        // *.myshopify.com host — the check is unchanged in strictness, only in what
        // shapes it can read.
        $issHost = $this->host((string) ($claims['iss'] ?? ''));
        $destHost = $this->host((string) ($claims['dest'] ?? ''));
        if ($issHost === '' || $destHost === '' || $issHost !== $destHost) {
            return null;
        }

        return $claims;
    }

    /** Extract the *.myshopify.com host from a dest/iss claim. */
    public function shopDomainFromClaims(array $claims): string
    {
        return $this->host((string) ($claims['dest'] ?? ''));
    }

    /**
     * The shop host named by an iss/dest claim, whatever shape Shopify sent.
     *
     * The embedded admin's App Bridge sends full URLs (https://{shop}/admin), but
     * a CHECKOUT / CUSTOMER-ACCOUNT UI extension sends the BARE host — and
     * parse_url() reads a scheme-less string as a path, so PHP_URL_HOST came back
     * null and every extension token failed verification with no way to tell it
     * from a forged one. That is what left the thank-you upsell silently blank.
     *
     * ShopifyDomain::normalize is the project's existing gate for exactly this: it
     * strips an optional scheme and path, then validates against the myshopify.com
     * regex. Anything that is not a real shop domain still returns '' and still
     * fails the caller closed.
     */
    private function host(string $url): string
    {
        return ShopifyDomain::normalize($url);
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
