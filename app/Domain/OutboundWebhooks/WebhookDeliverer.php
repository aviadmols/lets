<?php

namespace App\Domain\OutboundWebhooks;

use App\Domain\OutboundWebhooks\Jobs\DeliverWebhookJob;
use App\Domain\OutboundWebhooks\Models\WebhookDelivery;
use App\Domain\OutboundWebhooks\Models\WebhookEndpoint;
use App\Support\Tenant;

/**
 * One attempt at one delivery, and what happens after it.
 *
 *   - The payload is built on the FIRST attempt and frozen on the row, so every
 *     retry repeats the same body (and the same event_id) the receiver may
 *     already have half-processed.
 *   - A failure retries on a widening backoff (1m, 5m, 30m, 2h, 6h), then stops
 *     at `failed`; the merchant can resend it from the screen.
 *   - A disabled or deleted endpoint ends the delivery — a merchant who switched
 *     a URL off must not keep receiving its backlog.
 *
 * Runs under the bound tenant (the job's TenantContext, or the screen's own).
 */
final class WebhookDeliverer
{
    // === CONSTANTS ===
    /** Seconds before attempt 2, 3, … — its length + 1 is the attempt ceiling. */
    public const BACKOFF = [60, 300, 1800, 7200, 21600];

    public const ERROR_ENDPOINT_GONE = 'endpoint_disabled';

    public const ERROR_SOURCE_GONE = 'source_missing';

    public function __construct(
        private readonly WebhookSender $sender,
        private readonly WebhookPayload $payloads,
    ) {}

    public static function maxAttempts(): int
    {
        return count(self::BACKOFF) + 1;
    }

    /**
     * @param  bool  $scheduleRetry  false for a manual resend — one try, the answer on screen
     */
    public function attempt(WebhookDelivery $delivery, bool $scheduleRetry = true): WebhookDelivery
    {
        if ($delivery->status === WebhookDelivery::STATUS_SUCCEEDED) {
            return $delivery;
        }

        $endpoint = WebhookEndpoint::query()->find($delivery->webhook_endpoint_id);
        if ($endpoint === null || (! $endpoint->is_active && ! $delivery->is_test)) {
            return $this->finish($delivery, WebhookDelivery::STATUS_FAILED, ['error' => self::ERROR_ENDPOINT_GONE]);
        }

        $payload = $delivery->payload ?? $this->build($delivery);
        if ($payload === null) {
            return $this->finish($delivery, WebhookDelivery::STATUS_FAILED, ['error' => self::ERROR_SOURCE_GONE]);
        }

        $result = $this->sender->send($endpoint, $payload, (int) $delivery->getKey());
        $attempts = (int) $delivery->attempts + 1;

        $outcome = [
            'payload' => $payload,
            'attempts' => $attempts,
            'response_status' => $result['status'],
            'response_body' => $result['body'],
            'error' => $result['error'],
            'next_attempt_at' => null,
        ];

        if ($result['ok']) {
            $endpoint->forceFill(['last_success_at' => now(), 'consecutive_failures' => 0])->save();

            return $this->finish($delivery, WebhookDelivery::STATUS_SUCCEEDED, $outcome + ['delivered_at' => now()]);
        }

        $endpoint->forceFill([
            'last_failure_at' => now(),
            'consecutive_failures' => (int) $endpoint->consecutive_failures + 1,
        ])->save();

        $canRetry = $scheduleRetry && ! $delivery->is_test && $attempts < self::maxAttempts();
        if (! $canRetry) {
            return $this->finish($delivery, WebhookDelivery::STATUS_FAILED, $outcome);
        }

        $delay = self::BACKOFF[$attempts - 1];
        $this->finish($delivery, WebhookDelivery::STATUS_RETRYING, ['next_attempt_at' => now()->addSeconds($delay)] + $outcome);

        DeliverWebhookJob::dispatch((int) $delivery->shop_id, (int) $delivery->getKey())->delay($delay);

        return $delivery;
    }

    /** @return array<string, mixed>|null */
    private function build(WebhookDelivery $delivery): ?array
    {
        $data = $this->payloads->dataFor((array) ($delivery->source ?? []));
        $shop = Tenant::current();

        return $data === null || $shop === null
            ? null
            : WebhookPayload::envelope($shop, (string) $delivery->topic, (string) $delivery->event_key, $data);
    }

    /** @param array<string, mixed> $patch */
    private function finish(WebhookDelivery $delivery, string $status, array $patch): WebhookDelivery
    {
        $delivery->forceFill(['status' => $status] + $patch)->save();

        return $delivery;
    }
}
