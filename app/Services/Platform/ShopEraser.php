<?php

namespace App\Services\Platform;

use App\Models\Shop;
use App\Models\User;
use App\Support\PlatformContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hard-delete a shop and EVERYTHING it owns — so the platform admin can wipe a shop and
 * re-create it cleanly (e.g. after a botched connect / a key mismatch left it unusable).
 *
 * Every tenant table's shop_id FK is cascadeOnDelete, so deleting the shop ROW cascades
 * all of its plans / payments / ledger / products / upsell flows / settings at the DB
 * level — no per-table enumeration to drift out of date. The shop's merchant logins are
 * deleted explicitly: users.shop_id is nullOnDelete, so the cascade would only ORPHAN
 * them, and the owner asked to clear the accounts too. Wrapped in a transaction so a
 * partial wipe can't leave half a shop. Platform-admin-gated at the call site; this
 * service just executes. Strictly one shop — the cascade is keyed on that shop_id, so it
 * can never reach another tenant's rows.
 */
final class ShopEraser
{
    /**
     * Erase the shop, its cascade-owned data, and its merchant accounts.
     *
     * @return array{users: int} how many merchant logins were removed (for the notice/audit)
     */
    public function erase(Shop $shop): array
    {
        return DB::transaction(function () use ($shop): array {
            $shopId = (int) $shop->getKey();
            $domain = $shop->displayDomain();

            // 1) The shop's merchant logins. NEVER a platform admin (they have no shop_id
            //    and are not tied to this shop). users.shop_id is nullOnDelete, so without
            //    this the cascade would leave orphaned, shopless accounts behind.
            $users = User::query()
                ->where('shop_id', $shopId)
                ->where('is_platform_admin', false)
                ->delete();

            // 2) Delete the shop → the DB cascades EVERY tenant table (all shop_id FKs are
            //    cascadeOnDelete): installment plans/payments/methods, payment_ledger,
            //    consents, products + variants + plan templates, upsell flows/offers/
            //    triggers/branches/events, activity_events, webhook_events, mail +
            //    billing settings, data-request exports. Scoped to this one shop only.
            $shop->delete();

            // 3) If a platform admin was "entered" into this shop, drop the now-dead
            //    selection so they fall back to platform mode instead of a 404 shell.
            if (PlatformContext::enteredShopId() === $shopId) {
                PlatformContext::exit();
            }

            Log::channel('stderr')->info('platform.shop_erased', [
                'shop_id' => $shopId,
                'domain' => $domain,
                'users_deleted' => $users,
            ]);

            return ['users' => $users];
        });
    }
}
