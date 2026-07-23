<?php

namespace App\Services\Shopify\Webhooks;

use App\Domain\ShopifySubscriptions\ContractMirror;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Log;

/**
 * The Shopify-Payments subscriptions rail's webhook intake:
 *
 *   subscription_contracts/create|update  → mirror the contract locally
 *   subscription_billing_attempts/success → resolve our attempt row + re-mirror
 *   subscription_billing_attempts/failure |
 *   subscription_billing_attempts/challenged → record the outcome for the retry loop
 *
 * The mirror never decides anything (Shopify owns the contract); the attempt rows
 * are matched by OUR idempotency key, which we set as the mutation's key — so the
 * outcome always finds the request that caused it. Idempotent by construction:
 * re-delivery re-applies the same terminal state.
 */
final class SubscriptionWebhookHandler implements WebhookHandler
{
    // === CONSTANTS ===
    private const TOPIC_SUCCESS = 'subscription_billing_attempts/success';
    private const TOPIC_FAILURE = 'subscription_billing_attempts/failure';
    private const TOPIC_CHALLENGED = 'subscription_billing_attempts/challenged';

    public function __construct(private readonly ContractMirror $mirror) {}

    public function handle(WebhookEvent $event): void
    {
        $shop = Shop::query()->find((int) $event->shop_id);
        if ($shop === null) {
            return;
        }

        $topic = (string) $event->topic;
        $payload = (array) ($event->raw_payload ?? []);

        if (str_starts_with($topic, 'subscription_contracts/')) {
            $this->mirror->fromWebhook($shop, $payload);

            return;
        }

        $this->resolveAttempt($shop, $topic, $payload);
    }

    /**
     * Record the outcome of a billing attempt we requested. Matched by OUR
     * idempotency key (echoed back by Shopify); an attempt we did not request is
     * logged and mirrored, never invented — a row born from an outcome would
     * bypass the one-attempt-per-cycle wall.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveAttempt(Shop $shop, string $topic, array $payload): void
    {
        $status = match ($topic) {
            self::TOPIC_SUCCESS => SubscriptionBillingAttempt::STATUS_SUCCEEDED,
            self::TOPIC_FAILURE => SubscriptionBillingAttempt::STATUS_FAILED,
            self::TOPIC_CHALLENGED => SubscriptionBillingAttempt::STATUS_CHALLENGED,
            default => null,
        };
        if ($status === null) {
            return;
        }

        $key = (string) ($payload['idempotency_key'] ?? '');

        $attempt = $key !== ''
            ? SubscriptionBillingAttempt::query()->where('idempotency_key', $key)->first()
            : null;

        if ($attempt === null) {
            Log::info('shopify_subscriptions.attempt_outcome_unmatched', [
                'shop_id' => $shop->getKey(), 'topic' => $topic, 'key' => $key,
            ]);
        } else {
            $attempt->forceFill([
                'status' => $status,
                'shopify_attempt_gid' => (string) ($payload['admin_graphql_api_id'] ?? '') ?: $attempt->shopify_attempt_gid,
                'shopify_order_gid' => $this->orderGid($payload) ?? $attempt->shopify_order_gid,
                'error_code' => (string) ($payload['error_code'] ?? '') ?: null,
                'error_message' => (string) ($payload['error_message'] ?? '') ?: null,
                'resolved_at' => now(),
            ])->save();
        }

        // Whatever happened, our copy of the contract just changed (next billing
        // date advanced, or status moved on repeated failure). Re-mirror it.
        $this->remirrorContract($shop, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function orderGid(array $payload): ?string
    {
        $id = $payload['order_id'] ?? null;

        if ($id === null || $id === '') {
            return null;
        }

        $id = (string) $id;

        return str_starts_with($id, 'gid://') ? $id : 'gid://shopify/Order/'.$id;
    }

    /**
     * Touch the mirrored contract's sync marker from the attempt payload. The full
     * re-read happens lazily on the next portal/admin load; here we only record
     * that our copy is stale enough to matter.
     *
     * @param  array<string, mixed>  $payload
     */
    private function remirrorContract(Shop $shop, array $payload): void
    {
        $contractId = $payload['subscription_contract_id'] ?? null;
        if ($contractId === null) {
            return;
        }

        SubscriptionContract::query()
            ->where('shop_id', (int) $shop->getKey())
            ->where('shopify_gid', 'gid://shopify/SubscriptionContract/'.$contractId)
            ->update(['synced_at' => null]); // null = "stale, re-read before trusting"
    }
}
