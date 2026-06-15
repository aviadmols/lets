<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Products\Data\ProductData;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persists a source-agnostic ProductData (+ its variants) into the local cache,
 * idempotently. The SHARED write path: both ImportShopProductsJob (bulk) and
 * ProductWebhookHandler (one product) call this, so the upsert rules live in
 * exactly one place.
 *
 * Tenancy: MUST run with a Tenant already bound (the import job + webhook job both
 * bind it via TenantContext). updateOrCreate/queries go through the BelongsToShop
 * global scope, so shop_id is auto-stamped and matched — we NEVER pass shop_id by
 * hand and NEVER bypass the scope. The unique (shop_id, source, external_id) /
 * (shop_id, product_id, external_variant_id) keys make a re-run a no-op update.
 */
final class ProductUpserter
{
    /**
     * Upsert one product + its variants for the CURRENTLY BOUND tenant. Returns the
     * persisted Product. Variants no longer present upstream are pruned (a variant
     * deleted in Shopify should not linger in the cache).
     */
    public function upsert(ProductData $data, string $source): Product
    {
        if (! Tenant::check()) {
            // Fail closed: an unbound upsert would either stamp a null shop_id or
            // (worse) be invisible to the scope — never write tenant data blind.
            throw new RuntimeException('ProductUpserter::upsert requires a bound Tenant.');
        }

        return DB::transaction(function () use ($data, $source): Product {
            // Match on the import upsert key; shop_id is supplied by the global
            // scope's creating() hook, so it can never be spoofed or crossed.
            $product = Product::query()->updateOrCreate(
                ['source' => $source, 'external_id' => $data->externalId],
                [
                    'title' => $data->title,
                    'handle' => $data->handle,
                    'image_url' => $data->imageUrl,
                    'status' => $data->status,
                    'online_store_status' => $data->onlineStoreStatus,
                    'tags' => $data->tags,
                    'updated_at_external' => $data->updatedAtExternal,
                    'synced_at' => now(),
                ],
            );

            $this->syncVariants($product, $data);

            return $product;
        });
    }

    private function syncVariants(Product $product, ProductData $data): void
    {
        $keptExternalIds = [];

        foreach ($data->variants as $variant) {
            $keptExternalIds[] = $variant->externalId;

            // product_id is part of the unique key; tenant scope adds shop_id.
            ProductVariant::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'external_variant_id' => $variant->externalId,
                ],
                [
                    'title' => $variant->title,
                    'sku' => $variant->sku,
                    'price' => $variant->price,
                    'position' => $variant->position,
                ],
            );
        }

        // Prune variants that vanished upstream — but only within THIS product
        // (and, via the global scope, this shop). Plan templates targeting a
        // removed variant are the resolver's concern, not ours.
        $stale = $product->variants();
        if ($keptExternalIds !== []) {
            $stale = $stale->whereNotIn('external_variant_id', $keptExternalIds);
        }
        $stale->delete();
    }
}
