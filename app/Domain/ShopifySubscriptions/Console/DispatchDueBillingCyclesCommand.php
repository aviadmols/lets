<?php

namespace App\Domain\ShopifySubscriptions\Console;

use App\Domain\ShopifySubscriptions\Jobs\BillingAttemptJob;
use App\Models\SubscriptionContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The scheduler's fan-out for the Shopify-Payments rail — the sibling of
 * payplus:dispatch-due, and the reason billing happens at all: Shopify does NOT
 * auto-bill a subscription contract; the app must ask, cycle by cycle.
 *
 * Scans mirrored contracts whose next_billing_date has arrived (the
 * (shop_id, status, next_billing_date) index makes this O(due-now)) and
 * dispatches exactly one BillingAttemptJob per contract per cycle. The cycle key
 * is the DATE the cycle bills on — deterministic, so every layer of the
 * double-billing wall (job uniqueness, the attempt row, Shopify's idempotency
 * key) derives the same identity for the same cycle.
 *
 * AUDITED cross-tenant scan: acrossAllTenants is paired with per-row shop_id, and
 * each dispatched job re-binds its own tenant (TenantContext).
 */
final class DispatchDueBillingCyclesCommand extends Command
{
    // === CONSTANTS ===
    protected $signature = 'shopify-subscriptions:dispatch-due {--chunk=50}';

    protected $description = 'Dispatch a billing attempt for every mirrored subscription contract whose cycle is due.';

    /** Scheduler heartbeat, surfaced on the observability page like its sibling. */
    private const HEARTBEAT_KEY = 'shopify_subscriptions:dispatch_due:last_run_at';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dispatched = 0;

        SubscriptionContract::acrossAllTenants()
            ->whereIn('status', SubscriptionContract::BILLABLE_STATUSES)
            ->whereNotNull('next_billing_date')
            ->where('next_billing_date', '<=', now())
            ->chunkById($chunk, function ($contracts) use (&$dispatched): void {
                foreach ($contracts as $contract) {
                    BillingAttemptJob::dispatch(
                        (int) $contract->shop_id,
                        (int) $contract->getKey(),
                        $contract->next_billing_date->toDateString(),
                    );
                    $dispatched++;
                }
            });

        Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String());
        $this->info("Dispatched {$dispatched} due billing attempt(s).");

        return self::SUCCESS;
    }
}
