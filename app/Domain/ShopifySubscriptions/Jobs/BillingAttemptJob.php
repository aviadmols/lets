<?php

namespace App\Domain\ShopifySubscriptions\Jobs;

use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Ask Shopify to bill ONE contract for ONE due cycle. The app-driven half of the
 * Shopify-Payments rail: Shopify vaults the card and processes the payment, but
 * nothing bills until we call subscriptionBillingAttemptCreate.
 *
 * The double-billing wall has three layers, mirroring ChargeJob's shape:
 *   1. ShouldBeUnique on shop+contract+cycle collapses scheduler overlap;
 *   2. the (shop, contract, billing_cycle_key) UNIQUE row is opened BEFORE the
 *      API call — a redelivered job finds it and stops;
 *   3. the same idempotency key is sent to SHOPIFY on the mutation, so even a
 *      crash between our INSERT and the call cannot double-bill.
 *
 * The outcome arrives asynchronously via subscription_billing_attempts/success|
 * failure webhooks (SubscriptionWebhookHandler resolves our row). shop_id is
 * carried EXPLICITLY; TenantContext binds and always clears it.
 */
final class BillingAttemptJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_CHARGES;

    /** ShouldBeUnique lock TTL (seconds) — released when the job completes. */
    public int $uniqueFor = 600;

    public int $tries = 1; // retries are cycle-scheduled by the scanner, not queue-level

    public function __construct(
        public readonly int $shopId,
        public readonly int $contractId,
        public readonly string $billingCycleKey,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return sprintf('shop:%d:sub:%d:cycle:%s', $this->shopId, $this->contractId, $this->billingCycleKey);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(): void
    {
        $shop = Shop::query()->find($this->shopId);
        if ($shop === null || ! $shop->isLive()) {
            return;
        }

        // Tenant-scoped lookup (TenantContext bound the shop); explicit key match.
        $contract = SubscriptionContract::query()->whereKey($this->contractId)->first();
        if ($contract === null || ! $contract->isBillable()) {
            return; // paused/cancelled since the scan — nothing to bill
        }

        $key = sprintf('subattempt:%d:%d:%s', $this->shopId, $this->contractId, $this->billingCycleKey);

        // Layer 2: open the attempt row BEFORE the API call. An existing row for
        // this cycle — whatever its status — means an attempt was already made or
        // is in flight; we never ask twice for one cycle.
        $attempt = SubscriptionBillingAttempt::query()
            ->where('subscription_contract_id', $contract->getKey())
            ->where('billing_cycle_key', $this->billingCycleKey)
            ->first();

        if ($attempt !== null) {
            return;
        }

        $attempt = new SubscriptionBillingAttempt();
        $attempt->forceFill([
            'shop_id' => $this->shopId,
            'subscription_contract_id' => (int) $contract->getKey(),
            'billing_cycle_key' => $this->billingCycleKey,
            'idempotency_key' => $key,
            'status' => SubscriptionBillingAttempt::STATUS_REQUESTED,
            'requested_at' => now(),
        ])->save();

        try {
            // Layer 3: Shopify receives OUR key, so a duplicate request collapses
            // on their side too. The origin time pins the attempt to its cycle.
            $body = ShopifyClientFactory::for($shop)->graphql(<<<'GQL'
            mutation billContract($subscriptionContractId: ID!, $subscriptionBillingAttemptInput: SubscriptionBillingAttemptInput!) {
              subscriptionBillingAttemptCreate(
                subscriptionContractId: $subscriptionContractId,
                subscriptionBillingAttemptInput: $subscriptionBillingAttemptInput
              ) {
                subscriptionBillingAttempt { id }
                userErrors { field message }
              }
            }
            GQL, [
                'subscriptionContractId' => (string) $contract->shopify_gid,
                'subscriptionBillingAttemptInput' => [
                    'idempotencyKey' => $key,
                    'originTime' => $this->billingCycleKey.'T00:00:00Z',
                ],
            ]);
        } catch (\Throwable $e) {
            // Transport failure: outcome UNKNOWN — Shopify may have received it.
            // The row stays `requested` and is never re-asked for this cycle; the
            // success/failure webhook resolves it if the request did land. The
            // same asymmetry as the invoicing module's unresolved documents.
            Log::warning('shopify_subscriptions.billing_attempt_transport_failed', [
                'shop_id' => $this->shopId, 'contract_id' => $this->contractId,
                'cycle' => $this->billingCycleKey, 'error' => $e->getMessage(),
            ]);

            return;
        }

        $payload = (array) data_get($body, 'data.subscriptionBillingAttemptCreate', []);
        $errors = (array) ($payload['userErrors'] ?? []);

        if ($errors !== []) {
            // Shopify REFUSED (contract not billable, bad input) — nothing was
            // created, so this is a terminal failure for the cycle, recorded as such.
            $attempt->forceFill([
                'status' => SubscriptionBillingAttempt::STATUS_FAILED,
                'error_code' => 'rejected',
                'error_message' => json_encode($errors, JSON_UNESCAPED_UNICODE),
                'resolved_at' => now(),
            ])->save();

            return;
        }

        $gid = (string) data_get($payload, 'subscriptionBillingAttempt.id', '');
        if ($gid !== '') {
            $attempt->forceFill(['shopify_attempt_gid' => $gid])->save();
        }
        // Success/failure of the PAYMENT arrives via webhook; the row stays
        // `requested` until SubscriptionWebhookHandler resolves it.
    }
}
