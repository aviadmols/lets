<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promote aviadmols@gmail.com to platform admin.
 *
 * The user exists in the database but has is_platform_admin = false and no
 * shop_id, so canAccessPanel() returns false and every /admin request yields
 * 403 Forbidden. This migration sets is_platform_admin = true via a direct DB
 * update (bypassing the guarded flag on the User model, which is the correct
 * deliberate path for privilege escalation) and explicitly keeps shop_id = null
 * — platform admins are not bound to a single tenant.
 */
return new class extends Migration
{
    private const EMAIL = 'aviadmols@gmail.com';

    public function up(): void
    {
        DB::table('users')
            ->where('email', self::EMAIL)
            ->update([
                'is_platform_admin' => true,
                'shop_id'           => null,
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', self::EMAIL)
            ->update([
                'is_platform_admin' => false,
            ]);
    }
};
