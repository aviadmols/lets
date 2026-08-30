<?php

namespace App\Domain\Mail\SendGrid;

use App\Domain\Mail\Contracts\SenderDomainProvider;
use App\Models\PlatformMailSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The platform's SendGrid account, as the only three calls we make against it.
 *
 * DOMAIN AUTHENTICATION, not sending: mail leaves over the SMTP relay (see
 * MailTransport), and this class exists for the paperwork around it — asking
 * SendGrid to authenticate a merchant's domain, reading back the CNAMEs the
 * merchant must publish, and asking SendGrid to look again once they say they
 * have.
 *
 * ONE ACCOUNT, MANY MERCHANTS. Every domain here belongs to the platform's
 * SendGrid account; a merchant never holds a key. That is the whole point of
 * the arrangement — one sending reputation we can actually manage — and it is
 * also why `remove()` exists: a shop that leaves must not keep an authenticated
 * domain on our account forever.
 *
 * NEVER THROWS ON A PROVIDER FAULT. Every method answers null (or false) and
 * logs; a settings screen that 500s because a third party is slow is worse than
 * one that says "we could not reach the provider, try again".
 */
final class SendGridClient implements SenderDomainProvider
{
    // === CONSTANTS ===
    /** Seconds before we stop waiting on the provider. A settings screen is waiting. */
    private const TIMEOUT = 15;

    /** SendGrid's own subuser-less default; we authenticate under the platform account. */
    private const DEFAULT_SUBDOMAIN = 'mail';

    /** Statuses that mean the KEY was refused, not that the provider was away. */
    public const UNAUTHORIZED_STATUSES = [401, 403];

    /**
     * Why the last call came back null.
     *
     * Every failure used to flatten into a single "we could not reach the
     * provider" — which told an owner whose key had simply been refused that
     * nothing was broken, and sent them waiting for a network that was fine.
     * The status and the provider's own words are kept so the screen can tell
     * a refused key from an outage.
     *
     * @var array{status: ?int, message: string}|null
     */
    private ?array $lastError = null;

    /** @return array{status: ?int, message: string}|null */
    public function lastError(): ?array
    {
        return $this->lastError;
    }

    /** True when the provider refused the key rather than failing to answer. */
    public function lastCallWasUnauthorized(): bool
    {
        return in_array($this->lastError['status'] ?? null, self::UNAUTHORIZED_STATUSES, true);
    }

    /**
     * Is the platform account configured at all?
     *
     * Through PlatformMailSettings, never straight off the config: the owner may
     * have saved the key on the platform screen rather than into a deploy
     * variable, and a reader that only knew about env would tell every merchant
     * the service is not connected while it happily sends.
     */
    public static function configured(): bool
    {
        return PlatformMailSettings::current()->isConnected();
    }

    /** The key this call authenticates with — saved value first, env second. */
    private static function key(): string
    {
        return (string) PlatformMailSettings::current()->apiKey();
    }

    /**
     * Ask SendGrid to authenticate a domain, and get back the records the
     * merchant has to publish.
     *
     * `automatic_security` is ON: SendGrid then issues CNAMEs (not raw DKIM TXT
     * records) and rotates the keys behind them itself. It is the difference
     * between a merchant pasting three CNAMEs once and a merchant maintaining
     * DKIM keys they did not ask for.
     *
     * @return array{id: int, records: list<array<string, mixed>>, subdomain: string}|null
     */
    public function authenticateDomain(string $domain, ?string $subdomain = null): ?array
    {
        $subdomain = trim((string) ($subdomain ?: PlatformMailSettings::current()->subdomain()));

        $body = $this->post('/whitelabel/domains', [
            'domain' => $domain,
            'subdomain' => $subdomain !== '' ? $subdomain : self::DEFAULT_SUBDOMAIN,
            'automatic_security' => true,
        ]);

        if ($body === null || (int) ($body['id'] ?? 0) <= 0) {
            return null;
        }

        return [
            'id' => (string) $body['id'],
            'records' => self::flattenRecords($body['dns'] ?? []),
            'subdomain' => (string) ($body['subdomain'] ?? $subdomain),
        ];
    }

    /**
     * Ask SendGrid to CHECK the DNS now.
     *
     * The answer carries the per-record verdicts, which is what makes a failed
     * check actionable: "the domain is not verified" sends a merchant hunting,
     * "this one CNAME is missing" sends them to the right line in their DNS.
     *
     * @return array{valid: bool, records: list<array<string, mixed>>}|null
     */
    public function validateDomain(string $domainId): ?array
    {
        $body = $this->post('/whitelabel/domains/'.rawurlencode($domainId).'/validate', []);

        if ($body === null) {
            return null;
        }

        $results = (array) ($body['validation_results'] ?? []);

        return [
            'valid' => (bool) ($body['valid'] ?? false),
            'records' => self::flattenRecords($results),
        ];
    }

    /** The domain as SendGrid holds it now — used to re-read records we lost. */
    public function fetchDomain(string $domainId): ?array
    {
        $body = $this->get('/whitelabel/domains/'.rawurlencode($domainId));

        if ($body === null) {
            return null;
        }

        return [
            'id' => (string) ($body['id'] ?? $domainId),
            'valid' => (bool) ($body['valid'] ?? false),
            'records' => self::flattenRecords($body['dns'] ?? []),
            'subdomain' => (string) ($body['subdomain'] ?? ''),
        ];
    }

    /** Give the domain back. A shop that left keeps nothing on our account. */
    public function removeDomain(string $domainId): bool
    {
        try {
            return $this->request()->delete($this->url('/whitelabel/domains/'.rawurlencode($domainId)))->successful();
        } catch (\Throwable $e) {
            $this->noteFailure('delete', null, $e->getMessage());

            return false;
        }
    }

    // === Internals ===

    /**
     * SendGrid returns its records as an OBJECT keyed by purpose
     * (`mail_cname`, `dkim1`, `dkim2`, …), and the keys differ with the
     * account's settings — so they are flattened to a list and the purpose is
     * kept only as a label. A reader that expected `dkim1` to exist would break
     * the day an account was configured differently.
     *
     * @return list<array<string, mixed>>
     */
    private static function flattenRecords(mixed $dns): array
    {
        $out = [];

        foreach ((array) $dns as $key => $record) {
            $record = (array) $record;

            $host = trim((string) ($record['host'] ?? ''));
            $value = trim((string) ($record['data'] ?? ''));

            if ($host === '' || $value === '') {
                continue;
            }

            $out[] = [
                'key' => is_string($key) ? $key : '',
                'host' => $host,
                'type' => mb_strtoupper((string) ($record['type'] ?? 'cname')),
                'data' => $value,
                'valid' => (bool) ($record['valid'] ?? false),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed>|null */
    private function post(string $path, array $payload): ?array
    {
        try {
            $response = $this->request()->post($this->url($path), $payload);
        } catch (\Throwable $e) {
            $this->noteFailure($path, null, $e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            $this->noteFailure($path, $response->status(), self::providerMessage($response->json()));

            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    /** @return array<string, mixed>|null */
    private function get(string $path): ?array
    {
        try {
            $response = $this->request()->get($this->url($path));
        } catch (\Throwable $e) {
            $this->noteFailure($path, null, $e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            $this->noteFailure($path, $response->status(), self::providerMessage($response->json()));

            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    private function request(): PendingRequest
    {
        return Http::withToken(self::key())
            ->acceptJson()
            ->timeout(self::TIMEOUT);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.sendgrid.api_base'), '/').$path;
    }

    /** The key is never in the message — only the path and the provider's verdict. */
    private function noteFailure(string $path, ?int $status, string $reason): void
    {
        $this->lastError = ['status' => $status, 'message' => mb_substr($reason, 0, 300)];

        Log::warning('mail.sendgrid.call_failed', [
            'path' => $path,
            'status' => $status,
            'reason' => $reason,
        ]);
    }

    /** SendGrid states the real problem in errors[].message; surface it verbatim. */
    private static function providerMessage(mixed $body): string
    {
        $errors = is_array($body) ? ($body['errors'] ?? []) : [];

        $messages = [];
        foreach ((array) $errors as $error) {
            $text = trim((string) ($error['message'] ?? ''));
            if ($text !== '') {
                $messages[] = $text;
            }
        }

        return $messages !== [] ? implode('; ', $messages) : '';
    }
}
