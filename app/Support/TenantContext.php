<?php

namespace App\Support;

use App\Models\Shop;

/**
 * Job middleware that binds the Tenant for the lifetime of a single queued job
 * and ALWAYS clears it afterwards. Workers are long-lived; without this, a job
 * that left Tenant bound would let the NEXT job on the same worker read the
 * wrong shop's data. This is the worker-context-leakage fix.
 *
 * Usage in a job:
 *
 *     public function middleware(): array
 *     {
 *         return [new TenantContext($this->shopId)];
 *     }
 *
 * The job must carry shop_id EXPLICITLY (never infer it from global state).
 */
final class TenantContext
{
    // === CONSTANTS ===
    public const QUEUE_CHARGES = 'charges';
    public const QUEUE_WEBHOOKS = 'webhooks';
    public const QUEUE_SYNC = 'sync';
    public const QUEUE_INVOICES = 'invoices';
    public const QUEUE_UPSELL = 'upsell';

    public function __construct(private readonly int $shopId) {}

    public function handle(object $job, callable $next): mixed
    {
        // Resolve the tenant WITHOUT the global scope getting in the way:
        // Shop is the tenant model itself and is not BelongsToShop-scoped, but
        // we look it up unconditionally by primary key.
        $shop = Shop::query()
            ->whereKey($this->shopId)
            ->firstOrFail();

        return Tenant::run($shop, fn () => $next($job));
    }
}
