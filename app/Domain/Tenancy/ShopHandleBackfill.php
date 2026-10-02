<?php

namespace App\Domain\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Give every shop that has no handle yet the one ShopHandle would have given it
 * at install. Run by the add_handle_to_shops migration and safe to re-run by
 * hand: a shop that already has a handle is never touched, so a second run
 * assigns nothing.
 *
 * ISOLATED, the same discipline as the analytics/consent backfills: ids are read
 * first through the query builder (no model is hydrated, so an undecryptable
 * credential bag cannot abort the deploy), and each shop is handled in its own
 * try + DB::transaction (a savepoint inside the migration's transaction on
 * Postgres). A shop that fails is logged with its id and exception class and
 * skipped; the deploy goes on.
 */
final class ShopHandleBackfill
{
    // === CONSTANTS ===
    private const CHUNK = 200;

    private const TABLE = 'shops';

    /** @return array{assigned: int, skipped: int} */
    public function run(): array
    {
        $assigned = 0;
        $skipped = 0;

        DB::table(self::TABLE)
            ->select(['id', 'shopify_domain', 'woocommerce_domain', 'name'])
            ->whereNull('handle')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use (&$assigned, &$skipped): void {
                foreach ($rows as $row) {
                    $shopId = (int) $row->id;

                    try {
                        DB::transaction(function () use ($row, $shopId): void {
                            $handle = ShopHandle::forShop(
                                $row->shopify_domain,
                                $row->woocommerce_domain,
                                $row->name,
                                $shopId,
                            );

                            DB::table(self::TABLE)
                                ->where('id', $shopId)
                                ->whereNull('handle')
                                ->update(['handle' => $handle]);
                        });
                        $assigned++;
                    } catch (\Throwable $e) {
                        $skipped++;
                        Log::warning('migration.shop_handle_skipped', [
                            'shop_id' => $shopId,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });

        Log::info('migration.shop_handles_backfilled', ['assigned' => $assigned, 'skipped' => $skipped]);

        return ['assigned' => $assigned, 'skipped' => $skipped];
    }
}
