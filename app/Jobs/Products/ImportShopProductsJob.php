<?php

namespace App\Jobs\Products;

use App\Models\Shop;
use App\Services\Products\ProductSourceFactory;
use App\Services\Products\ProductUpserter;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Backfills (and refreshes) ONE shop's local product cache from its source.
 * Dispatched on OAuth install and on-demand "Refresh products". Idempotent: the
 * source-agnostic ProductUpserter upserts by external id, so a reinstall or a
 * re-run never duplicates rows.
 *
 * Tenancy (RELEASE-BLOCKER pattern, mirrors RegisterShopifyWebhooksJob):
 *   - shop_id is carried EXPLICITLY in the ctor.
 *   - TenantContext middleware binds the tenant for the job lifetime and ALWAYS
 *     clears it (workers are long-lived — no context leak to the next job).
 *   - ProductSourceFactory + ProductUpserter both run under that bound tenant, so
 *     the global scope auto-scopes every write to this shop. No shop_id is ever
 *     passed by hand; the scope can't be crossed.
 *
 * Runs on the `sync` queue (bounded workers) so a fan-out across many shops never
 * bursts every store's catalog read at once.
 */
final class ImportShopProductsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_SYNC;
    /** Hard cap on pages walked, so a runaway cursor can't loop forever. */
    private const MAX_PAGES = 1000;

    public int $tries = 3;

    public function __construct(public readonly int $shopId)
    {
        $this->onQueue(self::QUEUE);
    }

    /** Bind the tenant for the job lifetime; clears in finally (worker-safe). */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(ProductUpserter $upserter): void
    {
        $shop = Shop::query()->whereKey($this->shopId)->first();
        if ($shop === null || ! $shop->isLive()) {
            return;
        }

        $source = ProductSourceFactory::for($shop);
        $sourceName = $source->platform();

        $cursor = null;
        $pages = 0;
        $imported = 0;

        do {
            $page = $source->fetchPage($shop, $cursor);

            foreach ($page->items as $product) {
                $upserter->upsert($product, $sourceName);
                $imported++;
            }

            $cursor = $page->nextCursor;
            $pages++;
        } while ($cursor !== null && $pages < self::MAX_PAGES);

        Log::info('products.import.completed', [
            'shop_id' => $this->shopId,
            'source' => $sourceName,
            'products' => $imported,
            'pages' => $pages,
        ]);
    }

    /**
     * Surface WHY the import failed — tagged to the shop, in both stderr and Horizon's
     * failed-job detail — so it never reads as a cryptic stack trace. By far the most
     * common cause is a store that isn't actually connected: its credentials bag can't
     * be decrypted (e.g. it was encrypted under an old key) → the client factory reports
     * "no … credentials". Turn that into an actionable hint instead of a mystery.
     */
    public function failed(\Throwable $e): void
    {
        $message = $e->getMessage();
        $credentialsIssue = str_contains($message, 'credentials')
            || str_contains($message, 'access token');

        Log::error('products.import.failed', [
            'shop_id' => $this->shopId,
            'error' => $message,
            'hint' => $credentialsIssue
                ? 'The store is not connected, or its stored credentials cannot be read. '
                    .'Re-connect the plugin / re-mint the connection token, then refresh products.'
                : null,
        ]);
    }
}
