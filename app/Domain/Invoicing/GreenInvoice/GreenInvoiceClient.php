<?php

namespace App\Domain\Invoicing\GreenInvoice;

use App\Models\Shop;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Green Invoice ("Morning") REST client, bound to ONE shop's credentials.
 *
 * Credentials are CONSTRUCTOR STATE — decrypted once by InvoiceProviderFactory
 * and never read from config() at call time, so an instance can never charge a
 * document to the wrong tenant's books. Operational config (base URLs, timeout,
 * token TTL) is platform-wide and comes from config/invoicing.php.
 *
 * Auth is a short-lived JWT from POST /account/token {id, secret}. It is cached
 * per shop AND per key-id: re-keying a shop must invalidate the cached token
 * immediately, or the new keys would appear not to take effect until the TTL
 * expired. The cached TTL is clamped UNDER the token's real lifetime so a token
 * can never expire mid-flight.
 *
 * Every method returns data or null — this client NEVER throws across its
 * boundary, and never logs a request body (documents carry customer PII).
 */
final class GreenInvoiceClient
{
    // === CONSTANTS ===
    private const PATH_TOKEN = '/account/token';
    private const PATH_DOCUMENTS = '/documents';

    /** Cache key namespace. Includes shop id + a hash of the key id. */
    private const CACHE_PREFIX = 'invoicing:green_invoice:token:';

    /** Never cache a token for longer than this, whatever the API claims. */
    private const TOKEN_TTL_CEILING = 3000;

    /** Safety margin (seconds) subtracted from a server-reported expiry. */
    private const TOKEN_TTL_MARGIN = 60;

    /** Machine-readable failure reasons surfaced to the settings screen. */
    public const REASON_NO_CREDENTIALS = 'no_credentials';
    public const REASON_UNAUTHORIZED = 'unauthorized';
    public const REASON_REJECTED = 'rejected';
    /** The document POST itself failed in transport — a document MAY exist. */
    public const REASON_TRANSPORT = 'transport';
    /**
     * The TOKEN request failed in transport, so we never reached POST /documents.
     * Distinct from REASON_TRANSPORT precisely because it PROVES nothing was
     * created — which is what makes it safe to retry (see
     * IssuedDocument::RETRYABLE_FAILURES). Collapsing the two would strand a
     * document every time the auth endpoint blipped.
     */
    public const REASON_TOKEN_TRANSPORT = 'token_transport';

    /** The last failure reason, for the caller's masked error message. */
    public ?string $lastReason = null;

    /** The last failure detail (provider text), safe to show a merchant. */
    public ?string $lastMessage = null;

    /** The last raw response body, for the audit trail (masked by the caller). */
    public array $lastResponse = [];

    /**
     * @param array{provider:string, api_key_id:?string, api_secret:?string, environment:string} $credentials
     *   The decrypted per-shop bag from Shop::invoicingConfig().
     */
    public function __construct(
        private readonly array $credentials,
        private readonly int $shopId,
        private readonly int $timeout,
    ) {}

    /**
     * Obtain (or reuse) an access token. Returns null on failure and sets
     * lastReason/lastMessage. A cached token is reused across every call in the
     * TTL — a run of ten documents costs one token request, not ten.
     */
    public function token(bool $forceRefresh = false): ?string
    {
        $keyId = (string) ($this->credentials['api_key_id'] ?? '');
        $secret = (string) ($this->credentials['api_secret'] ?? '');

        if ($keyId === '' || $secret === '') {
            $this->fail(self::REASON_NO_CREDENTIALS, 'No Green Invoice credentials configured.');

            return null;
        }

        $cacheKey = $this->tokenCacheKey($keyId);

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        } elseif (($cached = Cache::get($cacheKey)) !== null && is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http()
                ->post($this->url(self::PATH_TOKEN), ['id' => $keyId, 'secret' => $secret]);
        } catch (Throwable $e) {
            // TOKEN transport, not document transport: we never reached the documents
            // endpoint, so nothing was created and a retry is safe.
            $this->fail(self::REASON_TOKEN_TRANSPORT, class_basename($e));

            return null;
        }

        $body = (array) ($response->json() ?? []);
        $this->lastResponse = $body;

        if (! $response->successful()) {
            $this->fail(
                $response->status() === 401 || $response->status() === 403
                    ? self::REASON_UNAUTHORIZED
                    : self::REASON_REJECTED,
                $this->errorTextFrom($body, $response->status()),
            );

            return null;
        }

        $token = (string) ($body['token'] ?? '');
        if ($token === '') {
            $this->fail(self::REASON_REJECTED, 'The token response carried no token.');

            return null;
        }

        Cache::put($cacheKey, $token, $this->ttlFor($body));
        $this->clearFailure();

        return $token;
    }

    /**
     * POST /documents. Returns the decoded body on success, null on failure (with
     * lastReason/lastMessage/lastResponse set).
     *
     * A 401 is retried ONCE with a force-refreshed token: a token can expire
     * between the cache read and the request, and losing a document to that race
     * would leave the merchant with money recorded and no paperwork.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function createDocument(array $payload): ?array
    {
        $body = $this->sendDocument($payload, forceFreshToken: false);

        if ($body === null && $this->lastReason === self::REASON_UNAUTHORIZED) {
            $body = $this->sendDocument($payload, forceFreshToken: true);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function sendDocument(array $payload, bool $forceFreshToken): ?array
    {
        $token = $this->token($forceFreshToken);
        if ($token === null) {
            return null; // lastReason already set by token()
        }

        try {
            $response = $this->http()
                ->withToken($token)
                ->post($this->url(self::PATH_DOCUMENTS), $payload);
        } catch (Throwable $e) {
            $this->fail(self::REASON_TRANSPORT, class_basename($e));

            return null;
        }

        $body = (array) ($response->json() ?? []);
        $this->lastResponse = $body;

        if (! $response->successful()) {
            $this->fail(
                $response->status() === 401 ? self::REASON_UNAUTHORIZED : self::REASON_REJECTED,
                $this->errorTextFrom($body, $response->status()),
            );

            // The payload is NEVER logged — a document body carries customer PII.
            Log::warning('invoicing.green_invoice.document_rejected', [
                'shop_id' => $this->shopId,
                'status' => $response->status(),
                'reason' => $this->lastReason,
            ]);

            return null;
        }

        $this->clearFailure();

        return $body;
    }

    // === Internals ===

    /** A configured JSON client. Callers add ->withToken() when auth is needed. */
    private function http(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->acceptJson()
            ->asJson();
    }

    /** The environment-correct base URL + path. */
    private function url(string $path): string
    {
        $isSandbox = ($this->credentials['environment'] ?? Shop::INVOICING_ENV_PRODUCTION)
            === Shop::INVOICING_ENV_SANDBOX;

        $base = (string) config(
            $isSandbox ? 'invoicing.green_invoice.base_url_sandbox' : 'invoicing.green_invoice.base_url',
        );

        return rtrim($base, '/').$path;
    }

    /**
     * Cache key: shop id + a hash of the key id. Hashing (never storing) the key
     * id keeps the credential out of the cache keyspace while still busting the
     * entry the moment the merchant re-keys.
     */
    private function tokenCacheKey(string $keyId): string
    {
        return self::CACHE_PREFIX.$this->shopId.':'.substr(hash('sha256', $keyId), 0, 16);
    }

    /**
     * How long to cache a token: the server-reported expiry minus a safety margin,
     * clamped to the configured TTL and a hard ceiling. `expires` is a unix
     * timestamp; a missing/nonsense value falls back to the configured TTL.
     *
     * @param  array<string, mixed>  $body
     */
    private function ttlFor(array $body): int
    {
        $configured = (int) config('invoicing.token_ttl', 1500);

        $expiresAt = (int) ($body['expires'] ?? 0);
        $remaining = $expiresAt > 0 ? $expiresAt - time() - self::TOKEN_TTL_MARGIN : 0;

        $ttl = $remaining > 0 ? min($remaining, $configured) : $configured;

        return max(60, min($ttl, self::TOKEN_TTL_CEILING));
    }

    /**
     * A merchant-safe error string from a provider error body. Green Invoice
     * answers `{errorCode, errorMessage}`; we fall back to the HTTP status so a
     * blank body never produces a blank reason.
     *
     * @param  array<string, mixed>  $body
     */
    private function errorTextFrom(array $body, int $status): string
    {
        $message = trim((string) ($body['errorMessage'] ?? $body['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        $code = trim((string) ($body['errorCode'] ?? ''));

        return $code !== '' ? 'Error '.$code : 'HTTP '.$status;
    }

    private function fail(string $reason, string $message): void
    {
        $this->lastReason = $reason;
        $this->lastMessage = $message;
    }

    private function clearFailure(): void
    {
        $this->lastReason = null;
        $this->lastMessage = null;
    }
}
