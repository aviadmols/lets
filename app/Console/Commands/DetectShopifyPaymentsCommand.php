<?php

namespace App\Console\Commands;

use App\Jobs\Shopify\DetectShopifyPaymentsJob;
use App\Models\Shop;
use App\Services\Shopify\ShopifyPaymentsDetector;
use App\Support\Tenant;
use Illuminate\Console\Command;

/**
 * Re-run Shopify-Payments detection for stores that were installed BEFORE the
 * detection existed (it otherwise only runs at install), or after a merchant
 * activates Shopify Payments on a store we had already answered for.
 *
 * AUDITED cross-tenant scan: the query is deliberately unscoped so the operator
 * can sweep every shop, and each shop is re-bound as its own tenant for the call.
 */
final class DetectShopifyPaymentsCommand extends Command
{
    // === CONSTANTS ===
    protected $signature = 'shopify:detect-payments {--shop= : one shopify domain, else every live shop} {--queue : dispatch jobs instead of running inline}';

    protected $description = 'Ask Shopify whether each store sells through Shopify Payments (live, test/dev, or not at all).';

    public function handle(ShopifyPaymentsDetector $detector): int
    {
        $query = Shop::query()->whereIn('status', Shop::LIVE_STATUSES);

        $domain = (string) ($this->option('shop') ?? '');
        if ($domain !== '') {
            $query->where('shopify_domain', $domain);
        }

        $shops = $query->get();
        if ($shops->isEmpty()) {
            $this->warn('No live shops matched.');

            return self::SUCCESS;
        }

        foreach ($shops as $shop) {
            if ($this->option('queue')) {
                DetectShopifyPaymentsJob::dispatch((int) $shop->getKey());
                $this->line(sprintf('%s → queued', $shop->displayDomain()));

                continue;
            }

            $status = Tenant::run($shop, fn (): string => $detector->detect($shop));
            $this->line(sprintf(
                '%s → %s (rail: %s)',
                $shop->displayDomain(),
                $status,
                $shop->fresh()->subscriptionRail(),
            ));
        }

        return self::SUCCESS;
    }
}
