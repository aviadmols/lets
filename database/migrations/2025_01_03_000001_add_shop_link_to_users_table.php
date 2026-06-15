<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User <-> Shop linkage (the merchant-login tenant binding).
 *
 * A normal merchant admin user belongs to EXACTLY ONE shop. The production
 * tenant binding (BindTenantFromUser) reads users.shop_id and binds it as the
 * Tenant for the panel request — so a forgotten where() still fails closed via
 * the BelongsToShop global scope.
 *
 *   - shop_id: nullable FK -> shops (indexed). Nullable because a platform-admin
 *     user (the app owner) legitimately has no single shop; a merchant user MUST
 *     have one and is denied panel access without it (fail closed).
 *   - is_platform_admin: a platform owner who may reach an explicit, audited
 *     cross-tenant path. Guarded from mass assignment on the User model. Default
 *     false — every user is a non-privileged merchant unless deliberately flagged.
 *
 * ON DELETE: nullOnDelete keeps the user row if its shop is hard-deleted
 * (shop/redact); the now-shopless user is then denied panel access by the
 * binding middleware until re-linked — never silently promoted to see all data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('shop_id')
                ->nullable()
                ->after('id')
                ->constrained('shops')
                ->nullOnDelete();

            $table->boolean('is_platform_admin')
                ->default(false)
                ->after('shop_id');

            // The hot path: resolve a user's tenant by shop_id.
            $table->index('shop_id', 'users_shop_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['shop_id']);
            $table->dropIndex('users_shop_id_index');
            $table->dropColumn(['shop_id', 'is_platform_admin']);
        });
    }
};
