<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-shop Shopify rate-limit + cost-awareness gate.
 *
 * Shopify throttles PER STORE, not per app — so we key everything by shop_id and
 * NEVER let one shop's sync burst exhaust another's budget (that's also why sync
 * is its own Horizon queue with bounded workers).
 *
 *   - REST: leaky bucket (~2 req/s standard). awaitTurn() spaces calls; backoff()
 *     honours Retry-After on 429.
 *   - GraphQL: cost-based. observeGraphqlCost() stores the store's remaining
 *     budget (throttleStatus.currentlyAvailable / restoreRate); awaitTurn() waits
 *     when the budget is below the configured buffer.
 *
 * Sleeps are real-time but tiny and capped; under test, the limiter is a no-op
 * (sleepEnabled=false) so the suite never blocks.
 */
final class ShopifyRateLimiter
{
    // === CONSTANTS ===
    private const REST_PER_SECOND = 2;           // standard plan leaky-bucket refill
    private const MAX_SLEEP_SECONDS = 5.0;       // never block a worker longer than this
    private const COST_CACHE_TTL = 60;           // seconds to remember a store's budget
    private const COST_CACHE_PREFIX = 'shopify:gql_cost:';

    public function __construct(private readonly bool $sleepEnabled = true) {}

    /**
     * Block (briefly) until this shop may make another call. Combines a leaky
     * bucket (REST cadence) with the GraphQL budget headroom check.
     */
    public function awaitTurn(int $shopId): void
    {
        $this->awaitGraphqlBudget($shopId);

        // Leaky bucket: at most REST_PER_SECOND hits per shop per second.
        $key = 'shopify:rest:'.$shopId;
        $waited = 0.0;
        while (RateLimiter::tooManyAttempts($key, self::REST_PER_SECOND)) {
            $seconds = max(0.05, (float) RateLimiter::availableIn($key));
            $this->sleep(min($seconds, self::MAX_SLEEP_SECONDS));
            $waited += $seconds;
            if ($waited >= self::MAX_SLEEP_SECONDS) {
                break;
            }
        }
        RateLimiter::hit($key, 1);
    }

    /** Honour a 429 Retry-After (or exponential backoff when absent). */
    public function backoff(int $shopId, ?float $retryAfterSeconds, int $attempt): void
    {
        $seconds = $retryAfterSeconds ?? min(self::MAX_SLEEP_SECONDS, 0.25 * (2 ** max(0, $attempt)));
        $this->sleep(min($seconds, self::MAX_SLEEP_SECONDS));
    }

    /**
     * Record the store's GraphQL budget from a response's extensions.cost block.
     *
     * @param  array<string, mixed>  $cost
     */
    public function observeGraphqlCost(int $shopId, array $cost): void
    {
        $available = (float) data_get($cost, 'throttleStatus.currentlyAvailable', 0);
        $restoreRate = (float) data_get($cost, 'throttleStatus.restoreRate', 50);
        if ($restoreRate <= 0) {
            $restoreRate = 50;
        }

        Cache::put(self::COST_CACHE_PREFIX.$shopId, [
            'available' => $available,
            'restore_rate' => $restoreRate,
        ], self::COST_CACHE_TTL);
    }

    /** Wait if the last-seen GraphQL budget is below the configured buffer. */
    private function awaitGraphqlBudget(int $shopId): void
    {
        $state = Cache::get(self::COST_CACHE_PREFIX.$shopId);
        if (! is_array($state)) {
            return;
        }

        $buffer = (float) config('shopify.graphql_cost_buffer', 50);
        $available = (float) ($state['available'] ?? 0);
        $restoreRate = (float) ($state['restore_rate'] ?? 50);

        if ($available < $buffer && $restoreRate > 0) {
            $wait = min(self::MAX_SLEEP_SECONDS, ($buffer - $available) / $restoreRate);
            $this->sleep($wait);
        }
    }

    private function sleep(float $seconds): void
    {
        if ($this->sleepEnabled && $seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
