<?php

namespace App\Domain\Tenancy;

use App\Models\Shop;
use App\Models\ShopHandleAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * A platform admin renames a shop's handle. The old handle is kept as an alias
 * for ShopHandleAlias::TTL_DAYS, so `<old>.app.lets.co.il` keeps answering with
 * a 301 to the new host — bookmarks and mailed links do not die on the spot.
 *
 * Refused (as a validation error on $field): a malformed or reserved handle,
 * and one held by another shop or by another shop's live alias. Taking back
 * one's OWN alias is allowed (and removes that alias). Everything happens in
 * one transaction and the resolver cache is dropped for both handles after.
 */
final class ShopHandleChanger
{
    // === CONSTANTS ===
    public const FIELD = 'handle';

    public function change(Shop $shop, string $requested, string $field = self::FIELD): Shop
    {
        $new = ShopHandle::normalise($requested);
        $old = (string) $shop->handle;

        if ($new === $old) {
            return $shop;
        }

        $problem = ShopHandle::problem($new);
        if ($problem !== null) {
            throw ValidationException::withMessages([$field => __('tenancy.handle.error.'.$problem)]);
        }

        if (! ShopHandle::isAvailable($new, (int) $shop->getKey())) {
            throw ValidationException::withMessages([$field => __('tenancy.handle.error.taken')]);
        }

        DB::transaction(function () use ($shop, $new, $old): void {
            // The new handle may be one of this shop's own aliases, or an expired
            // alias of anybody: either row would collide on the unique index.
            ShopHandleAlias::query()
                ->where('handle', $new)
                ->where(fn ($q) => $q->where('shop_id', $shop->getKey())->orWhere('expires_at', '<=', now()))
                ->delete();

            if ($old !== '') {
                ShopHandleAlias::query()->updateOrCreate(
                    ['handle' => $old],
                    [
                        'shop_id' => $shop->getKey(),
                        'expires_at' => now()->addDays(ShopHandleAlias::TTL_DAYS),
                        'created_at' => now(),
                    ],
                );
            }

            $shop->forceFill(['handle' => $new])->save();
        });

        ShopHandleResolver::forget($old, $new);

        Log::info('tenancy.handle_changed', [
            'shop_id' => $shop->getKey(),
            'from' => $old,
            'to' => $new,
        ]);

        return $shop;
    }
}
