<?php

namespace App\Services\Shopify;

/**
 * One Shopify offline-token grant, whichever of the three flows minted it:
 * the authorization-code callback, token exchange, or a refresh.
 *
 * All three POST the SAME endpoint and answer the SAME JSON, so they share one
 * shape here rather than each threading five loose values through the installer.
 *
 * THE FIELD THAT MATTERS IS `expiresIn`. Shopify no longer accepts non-expiring
 * offline tokens on the Admin API — every call with one returns
 *
 *   403 [API] Non-expiring access tokens are no longer accepted for the Admin API.
 *
 * and it only issues an expiring one when the request ASKS, by sending
 * `expiring=1` (there is no Partner-Dashboard setting for this). A token that
 * comes back with no `expires_in` is therefore not "an expiry we failed to read"
 * — it is a dead token, and `isExpiring()` says so.
 */
final readonly class ShopifyToken
{
    // === CONSTANTS ===
    /**
     * The form field that makes Shopify mint an EXPIRING offline token. Send it
     * on every grant: omit it and Shopify happily returns a non-expiring token
     * that the Admin API then rejects on first use.
     */
    public const EXPIRING_PARAM = 'expiring';
    public const EXPIRING_VALUE = '1';

    public function __construct(
        public string $accessToken,
        public ?string $scope = null,
        public ?int $expiresIn = null,
        public ?string $refreshToken = null,
        public ?int $refreshTokenExpiresIn = null,
    ) {}

    /**
     * Build from a token-endpoint response body. Returns null when there is no
     * access token to store — fail closed, exactly as the callers already do.
     *
     * @param  array<string, mixed>  $json
     */
    public static function fromResponse(array $json): ?self
    {
        $accessToken = (string) ($json['access_token'] ?? '');
        if ($accessToken === '') {
            return null;
        }

        $scope = (string) ($json['scope'] ?? '');

        return new self(
            accessToken: $accessToken,
            scope: $scope !== '' ? $scope : null,
            expiresIn: self::seconds($json['expires_in'] ?? null),
            refreshToken: ((string) ($json['refresh_token'] ?? '')) ?: null,
            refreshTokenExpiresIn: self::seconds($json['refresh_token_expires_in'] ?? null),
        );
    }

    /**
     * Did Shopify actually mint an EXPIRING token? False means the grant came
     * back legacy and the Admin API will reject it — worth logging loudly, since
     * the cause is ours (a missing `expiring=1`), not the merchant's.
     */
    public function isExpiring(): bool
    {
        return $this->expiresIn !== null && $this->expiresIn > 0;
    }

    /** The form parameters every grant must carry to get an expiring token. */
    public static function expiringParams(): array
    {
        return [self::EXPIRING_PARAM => self::EXPIRING_VALUE];
    }

    private static function seconds(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
