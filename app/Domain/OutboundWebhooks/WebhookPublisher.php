<?php

namespace App\Domain\OutboundWebhooks;

use App\Domain\OutboundWebhooks\Jobs\DeliverWebhookJob;
use App\Domain\OutboundWebhooks\Models\WebhookDelivery;
use App\Domain\OutboundWebhooks\Models\WebhookEndpoint;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "This happened" → one delivery row per endpoint that listens to it, and a job.
 *
 * Called from inside the money path (a ledger row entering `succeeded` is often
 * inside the charge's transaction), so it is built to be harmless there:
 *   - it waits for the surrounding transaction to COMMIT (DB::afterCommit) —
 *     a rolled-back charge announces nothing, and no write of ours can poison
 *     the charge's Postgres transaction;
 *   - it never throws;
 *   - the event key is deterministic and unique per endpoint, so the same event
 *     announced twice (a replayed webhook, a re-run listener) sends once.
 *
 * The shop is passed explicitly and bound here — never inferred.
 */
final class WebhookPublisher
{
    /** @param array<string, mixed> $source what the event is about (see WebhookPayload::SOURCE_*) */
    public function publish(int $shopId, string $topic, string $eventKey, array $source): void
    {
        DB::afterCommit(function () use ($shopId, $topic, $eventKey, $source): void {
            try {
                $this->fanOut($shopId, $topic, $eventKey, $source);
            } catch (\Throwable $e) {
                Log::warning('webhooks.publish_failed', [
                    'shop_id' => $shopId,
                    'topic' => $topic,
                    'event_key' => $eventKey,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * A Shopify-Payments cycle reached its outcome. Only the two terminal answers
     * are announced — `challenged` (3-D Secure pending) is not an outcome yet.
     */
    public function billingAttemptResolved(SubscriptionBillingAttempt $attempt): void
    {
        $topic = match ($attempt->status) {
            SubscriptionBillingAttempt::STATUS_SUCCEEDED => WebhookTopic::RECURRING_SUCCEEDED,
            SubscriptionBillingAttempt::STATUS_FAILED => WebhookTopic::RECURRING_FAILED,
            default => null,
        };

        if ($topic !== null) {
            $this->publish(
                (int) $attempt->shop_id,
                $topic,
                sprintf('sba:%d:%s', $attempt->getKey(), $attempt->status),
                ['kind' => WebhookPayload::SOURCE_BILLING_ATTEMPT, 'attempt_id' => (int) $attempt->getKey()],
            );
        }
    }

    /** @param array<string, mixed> $source */
    private function fanOut(int $shopId, string $topic, string $eventKey, array $source): void
    {
        $shop = Shop::query()->find($shopId);
        if ($shop === null) {
            return;
        }

        Tenant::run($shop, function () use ($shopId, $topic, $eventKey, $source): void {
            $endpoints = WebhookEndpoint::query()->where('is_active', true)->get()
                ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->listensTo($topic));

            foreach ($endpoints as $endpoint) {
                $delivery = WebhookDelivery::query()->firstOrCreate(
                    ['webhook_endpoint_id' => $endpoint->getKey(), 'event_key' => $eventKey],
                    ['topic' => $topic, 'source' => $source, 'status' => WebhookDelivery::STATUS_PENDING],
                );

                if ($delivery->wasRecentlyCreated) {
                    DeliverWebhookJob::dispatch($shopId, (int) $delivery->getKey());
                }
            }
        });
    }
}
