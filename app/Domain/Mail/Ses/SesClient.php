<?php

namespace App\Domain\Mail\Ses;

use App\Domain\Mail\Contracts\SenderDomainProvider;
use App\Models\PlatformMailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Amazon SES v2, as the four domain calls this platform makes.
 *
 * SES identifies a domain BY THE DOMAIN ITSELF — there is no numeric id to
 * keep, so the "provider domain id" stored against a shop is the domain name.
 * That is why the provider contract speaks in strings.
 *
 * Easy DKIM is what we ask for: SES generates the keys, hands back three
 * tokens, and rotates them itself. The merchant publishes three CNAMEs of the
 * form `{token}._domainkey.{domain}` → `{token}.dkim.amazonses.com` and never
 * touches a DKIM key. Same bargain as SendGrid's automatic security, and the
 * same shape of answer, which is why one screen serves both.
 *
 * A NOTE ON THE SUBDOMAIN: SendGrid signs under a subdomain of the merchant's
 * choosing; SES signs the domain itself. The parameter is accepted and ignored
 * rather than refused, so the shared screen does not have to grow a branch.
 *
 * NEVER THROWS. Every method answers null (or false) and records WHY, so a
 * refused credential can be told apart from an outage.
 */
final class SesClient implements SenderDomainProvider
{
    // === CONSTANTS ===
    /** Seconds before we stop waiting. A settings screen is waiting. */
    private const TIMEOUT = 15;

    /** Where Easy DKIM CNAMEs point. */
    public const DKIM_SUFFIX = '.dkim.amazonses.com';

    /** SES's own word for "the DNS is published and checks out". */
    private const DKIM_SUCCESS = 'SUCCESS';

    private const IDENTITIES_PATH = '/v2/email/identities';

    /** @var array{status: ?int, message: string}|null */
    private ?array $lastError = null;

    public function __construct(
        private readonly ?string $region = null,
        private readonly ?string $accessKeyId = null,
        private readonly ?string $secretAccessKey = null,
    ) {}

    /** Is the platform's SES account configured at all? */
    public static function configured(): bool
    {
        $settings = PlatformMailSettings::current();

        return $settings->sesRegion() !== null
            && $settings->sesAccessKeyId() !== null
            && $settings->sesSecretAccessKey() !== null;
    }

    public function lastError(): ?array
    {
        return $this->lastError;
    }

    public function lastCallWasUnauthorized(): bool
    {
        // SES answers 403 for a bad signature and for a key without the
        // permission; both mean "your credentials, not our weather".
        return in_array($this->lastError['status'] ?? null, [401, 403], true);
    }

    /**
     * Create the domain identity with Easy DKIM and return its CNAMEs.
     *
     * Already-existing is NOT a failure: an identity we asked for before is the
     * identity we want, so we read it back rather than refusing the merchant a
     * domain they already half-configured.
     */
    public function authenticateDomain(string $domain, ?string $subdomain = null): ?array
    {
        $created = $this->send('POST', self::IDENTITIES_PATH, [
            'EmailIdentity' => $domain,
            'DkimSigningAttributes' => ['NextSigningKeyLength' => 'RSA_2048_BIT'],
        ]);

        if ($created === null) {
            // AlreadyExists comes back 409 — the identity is ours; read it.
            if (($this->lastError['status'] ?? null) !== 409) {
                return null;
            }

            return $this->fetchDomain($domain);
        }

        return [
            'id' => $domain,
            'records' => self::recordsFrom($domain, (array) ($created['DkimAttributes'] ?? [])),
            'subdomain' => '',
        ];
    }

    public function validateDomain(string $domainId): ?array
    {
        // SES checks DNS on its own schedule; asking for the identity IS asking
        // for the current verdict. There is no separate "validate now" call, so
        // a merchant who has just published records may need one more look.
        $fetched = $this->fetchDomain($domainId);

        if ($fetched === null) {
            return null;
        }

        return ['valid' => $fetched['valid'], 'records' => $fetched['records']];
    }

    public function fetchDomain(string $domainId): ?array
    {
        $body = $this->send('GET', self::IDENTITIES_PATH.'/'.rawurlencode($domainId));

        if ($body === null) {
            return null;
        }

        $dkim = (array) ($body['DkimAttributes'] ?? []);

        return [
            'id' => $domainId,
            // BOTH must hold: the keys check out AND SES will actually send as
            // it. A domain whose DKIM passed but which is still suppressed is
            // not a domain a merchant can send from.
            'valid' => ((string) ($dkim['Status'] ?? '')) === self::DKIM_SUCCESS
                && (bool) ($body['VerifiedForSendingStatus'] ?? false),
            'records' => self::recordsFrom($domainId, $dkim),
            'subdomain' => '',
        ];
    }

    public function removeDomain(string $domainId): bool
    {
        return $this->send('DELETE', self::IDENTITIES_PATH.'/'.rawurlencode($domainId)) !== null;
    }

    // === Internals ===

    /**
     * The three Easy DKIM CNAMEs, in the shape the shared screen renders.
     *
     * @param  array<string, mixed>  $dkim
     * @return list<array<string, mixed>>
     */
    private static function recordsFrom(string $domain, array $dkim): array
    {
        $out = [];

        foreach ((array) ($dkim['Tokens'] ?? []) as $index => $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }

            $out[] = [
                'key' => 'dkim'.($index + 1),
                'host' => $token.'._domainkey.'.$domain,
                'type' => 'CNAME',
                'data' => $token.self::DKIM_SUFFIX,
                // SES reports one status for the set, not per record.
                'valid' => ((string) ($dkim['Status'] ?? '')) === self::DKIM_SUCCESS,
            ];
        }

        return $out;
    }

    /**
     * One signed call. Returns the decoded body, or null with the reason kept.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function send(string $method, string $path, ?array $payload = null): ?array
    {
        $settings = PlatformMailSettings::current();

        $region = $this->region ?? $settings->sesRegion();
        $accessKeyId = $this->accessKeyId ?? $settings->sesAccessKeyId();
        $secret = $this->secretAccessKey ?? $settings->sesSecretAccessKey();

        if ($region === null || $accessKeyId === null || $secret === null) {
            $this->noteFailure($path, null, 'ses_not_configured');

            return null;
        }

        $host = 'email.'.$region.'.amazonaws.com';
        $body = $payload === null ? '' : (string) json_encode($payload);

        $headers = (new SignatureV4($accessKeyId, $secret, $region))
            ->headers($method, $host, $path, $body, CarbonImmutable::now());

        try {
            $request = Http::withHeaders($headers + ['Content-Type' => 'application/json'])
                ->timeout(self::TIMEOUT);

            $response = $request->send($method, 'https://'.$host.$path, $body === '' ? [] : ['body' => $body]);
        } catch (\Throwable $e) {
            $this->noteFailure($path, null, $e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            $this->noteFailure($path, $response->status(), self::providerMessage($response->json()));

            return null;
        }

        $decoded = $response->json();

        // DELETE answers 200 with an empty body; that is a success, not a null.
        return is_array($decoded) ? $decoded : [];
    }

    /** SES states the real problem in `message`; surface it verbatim. */
    private static function providerMessage(mixed $body): string
    {
        if (! is_array($body)) {
            return '';
        }

        return trim((string) ($body['message'] ?? $body['Message'] ?? ''));
    }

    private function noteFailure(string $path, ?int $status, string $reason): void
    {
        $this->lastError = ['status' => $status, 'message' => mb_substr($reason, 0, 300)];

        Log::warning('mail.ses.call_failed', [
            'path' => $path,
            'status' => $status,
            'reason' => $reason,
        ]);
    }
}
