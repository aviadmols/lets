<?php

namespace App\Domain\Tenancy;

use App\Models\Shop;
use App\Models\ShopHandleAlias;
use Illuminate\Support\Facades\Cache;

/**
 * Host label → shop. The ONE lookup ResolveShopFromHost makes on every admin
 * request on a shop host, so it is cached per handle (5 minutes). What is cached
 * is the decision, never a model: `['shop', id]`, `['alias', current-handle]`
 * or `['none']` — the shop row itself is re-read by primary key, so a cached
 * answer can never carry stale credentials or a stale status.
 *
 * Every write that can change an answer (a shop created, a handle changed, an
 * alias added, a shop deleted) calls forget() for the handles involved.
 */
final class ShopHandleResolver
{
    // === CONSTANTS ===
    public const CACHE_PREFIX = 'tenancy:handle:';

    public const CACHE_SECONDS = 300;

    public const KIND_SHOP = 'shop';

    public const KIND_ALIAS = 'alias';

    public const KIND_NONE = 'none';

    /**
     * @return array{0: string, 1?: int|string} the cached decision for $handle
     */
    public function decide(string $handle): array
    {
        $handle = ShopHandle::normalise($handle);

        if (! ShopHandle::isValid($handle)) {
            return [self::KIND_NONE];
        }

        return Cache::remember(self::CACHE_PREFIX.$handle, self::CACHE_SECONDS, function () use ($handle): array {
            $shopId = Shop::query()->where('handle', $handle)->value('id');
            if ($shopId !== null) {
                return [self::KIND_SHOP, (int) $shopId];
            }

            $alias = ShopHandleAlias::query()->live()->where('handle', $handle)->first();
            $current = $alias !== null ? Shop::query()->whereKey($alias->shop_id)->value('handle') : null;

            return is_string($current) && $current !== ''
                ? [self::KIND_ALIAS, $current]
                : [self::KIND_NONE];
        });
    }

    /** The shop whose CURRENT handle is $handle, or null (an alias is not a shop). */
    public function shop(string $handle): ?Shop
    {
        $decision = $this->decide($handle);

        return $decision[0] === self::KIND_SHOP
            ? Shop::query()->whereKey($decision[1])->first()
            : null;
    }

    public static function forget(?string ...$handles): void
    {
        foreach ($handles as $handle) {
            if (is_string($handle) && $handle !== '') {
                Cache::forget(self::CACHE_PREFIX.ShopHandle::normalise($handle));
            }
        }
    }
}
