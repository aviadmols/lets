<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is on a shop's team, and which of them is its OWNER.
 *
 * TWO ROLES, NO COLUMN. A shop has exactly one owner — the oldest merchant login
 * linked to it, which is the login the store's install (Shopify OAuth / managed
 * install, the WooCommerce plugin's connect, onboarding) provisioned before
 * anybody could add a colleague. Everyone added later is a member. Deriving the
 * role instead of storing it means every shop that exists today already has
 * exactly one owner with no data migration, nobody is locked out of anything
 * they could reach before except managing OTHER people's logins, and the owner
 * cannot be demoted by a member editing a column.
 *
 * The owner can never lose that seat by accident: a login cannot delete itself
 * (TeamMemberResource::mayDelete), and only the owner may delete anyone.
 *
 * Platform admins are never team members and never the owner — they are the
 * operator, not the merchant — and every query here pins shop_id itself, because
 * User is the one admin model without BelongsToShop.
 */
final class ShopTeam
{
    // === CONSTANTS ===
    private const PLATFORM_ADMIN_COLUMN = 'is_platform_admin';

    /**
     * The merchant logins of ONE shop, platform admins excluded.
     *
     * @return Builder<User>
     */
    public static function merchantsOf(int $shopId): Builder
    {
        return User::query()
            ->where('shop_id', $shopId)
            ->where(fn (Builder $q): Builder => $q
                ->where(self::PLATFORM_ADMIN_COLUMN, false)
                ->orWhereNull(self::PLATFORM_ADMIN_COLUMN));
    }

    /** The shop's owner: its oldest merchant login. Deterministic by id. */
    public static function ownerOf(int $shopId): ?User
    {
        return self::merchantsOf($shopId)->orderBy('id')->first();
    }

    public static function isOwner(?User $user): bool
    {
        if (! $user instanceof User || $user->isPlatformAdmin() || $user->shop_id === null) {
            return false;
        }

        return (int) self::ownerOf((int) $user->shop_id)?->getKey() === (int) $user->getKey();
    }

    /**
     * May this login manage OTHER people's access to the shop (add, edit,
     * remove teammates)? The owner, or the operator acting inside the shop.
     */
    public static function mayManageOthers(?User $user): bool
    {
        return $user instanceof User && ($user->isPlatformAdmin() || self::isOwner($user));
    }
}
