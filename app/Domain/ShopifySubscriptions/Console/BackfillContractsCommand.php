<?php

namespace App\Domain\ShopifySubscriptions\Console;

use App\Domain\ShopifySubscriptions\ContractBackfill;
use App\Domain\ShopifySubscriptions\Jobs\BackfillContractsJob;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Console\Command;

/**
 * Mirror the subscription contracts that already exist at Shopify.
 *
 * Webhooks only ever announce a CHANGE, so contracts created before this app
 * could listen are invisible to it forever. This command is the one-time catch-up
 * — after an API-access approval lands, after a dead token is revived, or after a
 * shop moves onto the Shopify-Payments rail with live subscribers.
 *
 * Idempotent: keyed on (shop_id, shopify_gid), so re-running refreshes the same
 * rows. Safe to run whenever the screen looks emptier than the store.
 *
 * Runs one shop inline with --sync so an operator sees the outcome immediately
 * (this is a diagnosis moment, not a background chore); otherwise it queues a
 * tenant-bound job per shop, which is what a fan-out over many shops needs.
 *
 * AUDITED cross-tenant scan: acrossAllTenants is not used here — Shop is not a
 * tenant-scoped model — and every dispatched job re-binds its own tenant.
 */
final class BackfillContractsCommand extends Command
{
    // === CONSTANTS ===
    protected $signature = 'shopify-subscriptions:backfill
        {--shop= : One shop id or myshopify domain; omit for every shop on the Shopify-Payments rail}
        {--sync : Run inline and print the result instead of queueing}';

    protected $description = 'Mirror the subscription contracts that already exist at Shopify (webhooks only announce changes).';

    public function handle(ContractBackfill $backfill): int
    {
        $shops = $this->targets();

        if ($shops === []) {
            $this->warn('No matching shop on the Shopify-Payments rail.');

            return self::FAILURE;
        }

        foreach ($shops as $shop) {
            if (! $this->option('sync')) {
                BackfillContractsJob::dispatch((int) $shop->getKey());
                $this->line("queued  {$shop->shopify_domain}");

                continue;
            }

            // Inline runs must bind the tenant themselves — the mirror's lookup is
            // tenant-scoped and fails closed, so an unbound run would try to insert
            // a duplicate of every contract it just read.
            Tenant::set($shop);
            try {
                $result = $backfill->run($shop);
            } finally {
                Tenant::clear();
            }

            $this->line(match ($result['result']) {
                ContractBackfill::RESULT_OK => "ok      {$shop->shopify_domain} — mirrored {$result['mirrored']} contract(s)",
                ContractBackfill::RESULT_DENIED => "denied  {$shop->shopify_domain} — Shopify has not granted read_own_subscription_contracts yet",
                default => "failed  {$shop->shopify_domain} — {$result['reason']}",
            });
        }

        return self::SUCCESS;
    }

    /** @return array<int, Shop> */
    private function targets(): array
    {
        $query = Shop::query()->where('subscription_rail', Shop::RAIL_SHOPIFY_PAYMENTS);

        $selector = (string) ($this->option('shop') ?? '');
        if ($selector !== '') {
            // A shop named EXPLICITLY is honoured whatever its rail: an operator
            // asking for one shop by name knows which shop they mean.
            $query = Shop::query()->where(
                is_numeric($selector) ? 'id' : 'shopify_domain',
                is_numeric($selector) ? (int) $selector : $selector,
            );
        }

        return $query->get()->all();
    }
}
