<?php

namespace App\Domain\OutboundWebhooks;

use App\Domain\Brand\SafeSiteFetcher;
use App\Domain\OutboundWebhooks\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;

/**
 * POSTs one signed JSON to one merchant URL — a merchant-steered outbound
 * request, so it goes through the SAME walls as every other one
 * (SafeSiteFetcher::vet): public address only, web ports only, the connection
 * pinned to the address that was judged, and redirects NOT followed (a 3xx is a
 * failed delivery, never a second destination we did not vet).
 *
 * Signing (Stripe-style, so off-the-shelf verifiers work):
 *   X-Lets-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">
 * keyed with the endpoint's secret. The receiver recomputes it over the RAW body
 * and rejects a stale `t` to stop replays.
 *
 * Never throws: every outcome is a typed result the delivery row records.
 */
final class WebhookSender
{
    // === CONSTANTS ===
    public const TIMEOUT_SECONDS = 10;

    public const USER_AGENT = 'LETS-Webhooks/1.0';

    public const HEADER_SIGNATURE = 'X-Lets-Signature';

    public const HEADER_TOPIC = 'X-Lets-Topic';

    public const HEADER_EVENT_ID = 'X-Lets-Event-Id';

    public const HEADER_DELIVERY_ID = 'X-Lets-Delivery-Id';

    public const HEADER_TEST = 'X-Lets-Test';

    private const RESPONSE_EXCERPT_BYTES = 1000;

    private const ERROR_MAX = 250;

    public function __construct(private readonly SafeSiteFetcher $walls) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: ?int, body: ?string, error: ?string}
     */
    public function send(WebhookEndpoint $endpoint, array $payload, ?int $deliveryId = null): array
    {
        $vetted = $this->walls->vet((string) $endpoint->url);
        if ($vetted['reason'] !== null) {
            return self::result(false, null, null, 'blocked_url:'.$vetted['reason']);
        }

        $body = self::encode($payload);
        $timestamp = (string) now()->getTimestamp();

        $headers = [
            'User-Agent' => self::USER_AGENT,
            self::HEADER_SIGNATURE => self::signature($body, (string) $endpoint->secret, $timestamp),
            self::HEADER_TOPIC => (string) ($payload['topic'] ?? ''),
            self::HEADER_EVENT_ID => (string) ($payload['event_id'] ?? ''),
            self::HEADER_TEST => ! empty($payload['test']) ? 'true' : 'false',
        ];
        if ($deliveryId !== null) {
            $headers[self::HEADER_DELIVERY_ID] = (string) $deliveryId;
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false] + $vetted['options'])
                ->timeout(self::TIMEOUT_SECONDS)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post((string) $endpoint->url);
        } catch (\Throwable $e) {
            return self::result(false, null, null, mb_strcut('unreachable: '.$e->getMessage(), 0, self::ERROR_MAX));
        }

        $excerpt = mb_strcut((string) $response->body(), 0, self::RESPONSE_EXCERPT_BYTES);

        if ($response->successful()) {
            return self::result(true, $response->status(), $excerpt, null);
        }

        $error = $response->redirect() ? 'redirect_not_followed' : 'http_'.$response->status();

        return self::result(false, $response->status(), $excerpt, $error);
    }

    /** The exact bytes that are sent AND signed. */
    public static function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function signature(string $body, string $secret, string $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @return array{ok: bool, status: ?int, body: ?string, error: ?string} */
    private static function result(bool $ok, ?int $status, ?string $body, ?string $error): array
    {
        return ['ok' => $ok, 'status' => $status, 'body' => $body, 'error' => $error];
    }
}
