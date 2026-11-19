<?php

namespace App\Domain\OutboundWebhooks;

use App\Domain\OutboundWebhooks\Jobs\DeliverWebhookJob;
use App\Domain\OutboundWebhooks\Models\WebhookDelivery;
use App\Domain\OutboundWebhooks\Models\WebhookEndpoint;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Illuminate\Support\Str;
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
    // === CONSTANTS ===
    /** The statuses a plan is in before it has ever been active. */
    private const BEFORE_FIRST_ACTIVATION = ['draft', 'awaiting_first_payment', 'awaiting_activation'];

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

    /**
     * A subscription's status moved (HasGuardedStatus::transitionTo, the one legal
     * path). Announces subscription.status_changed for EVERY move, and
     * subscription.activated the first time the plan becomes active.
     *
     * Each move is its own event (a plan can go active → paused → active), so the
     * key carries a fresh id; "activated" is keyed by the plan alone, so it can
     * only ever be sent once per endpoint.
     *
     * @param  array<string, mixed>  $context  the transition's own context (e.g. action)
     */
    public function planStatusChanged(InstallmentPlan $plan, string $from, string $to, array $context = []): void
    {
        $source = [
            'kind' => WebhookPayload::SOURCE_PLAN_STATUS,
            'plan_id' => (int) $plan->getKey(),
            'from' => $from,
            'to' => $to,
            'action' => isset($context['action']) && is_scalar($context['action']) ? (string) $context['action'] : null,
            'at' => now()->toIso8601String(),
        ];

        $this->publish(
            (int) $plan->shop_id,
            WebhookTopic::SUBSCRIPTION_STATUS_CHANGED,
            sprintf('ps:%d:%s>%s:%s', $plan->getKey(), $from, $to, Str::uuid()->toString()),
            $source,
        );

        if ($to === PlanStatus::ACTIVE->value && in_array($from, self::BEFORE_FIRST_ACTIVATION, true)) {
            $this->publish(
                (int) $plan->shop_id,
                WebhookTopic::SUBSCRIPTION_ACTIVATED,
                sprintf('pa:%d', $plan->getKey()),
                $source,
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
