<?php

namespace App\Services\Products;

use App\Jobs\Products\ImportShopProductsJob;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Products\Data\ProductData;
use App\Support\Tenant;

/**
 * On-demand "Refresh products" seam for the admin. admin-design-system owns the
 * UI buttons (list header "Refresh all" + a per-product "Refresh"); this service
 * is the backend seam they call so the refresh logic lives here, not in a
 * Livewire component.
 *
 *   - refreshAll: re-runs the full import on the `sync` queue (async, bounded).
 *   - refreshOne: a synchronous single-product fetch+upsert (snappy for one row;
 *     the caller is already in a tenant-bound request).
 *
 * Both go through the SAME source + upserter the install/webhook paths use, so a
 * manual refresh and an automatic one are identical.
 */
final class ProductRefreshService
{
    public function __construct(private readonly ProductUpserter $upserter) {}

    /** Queue a full catalog re-sync for the shop (idempotent upsert). */
    public function refreshAll(Shop $shop): void
    {
        ImportShopProductsJob::dispatch($shop->getKey());
    }

    /**
     * Synchronously refresh ONE product by its external id. Returns the upserted
     * Product, or null when it no longer exists upstream (in which case the local
     * row, if any, is left for the delete webhook / next full sync to unlist).
     * Runs in the current tenant context (the admin request is already bound).
     */
    public function refreshOne(Shop $shop, string $externalId): ?Product
    {
        return Tenant::run($shop, function () use ($shop, $externalId): ?Product {
            $source = ProductSourceFactory::for($shop);
            $data = $source->fetchOne($shop, $externalId);
            if (! $data instanceof ProductData) {
                return null;
            }

            return $this->upserter->upsert($data, $source->platform());
        });
    }
}
