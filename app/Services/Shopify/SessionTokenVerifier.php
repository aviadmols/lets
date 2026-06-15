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

        // iss and dest must be the same shop (both are https://{shop}/admin URLs).
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

    private function host(string $url): string
    {
        return strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
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
