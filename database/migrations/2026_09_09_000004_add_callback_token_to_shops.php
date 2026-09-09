<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One opaque token per shop for the callbacks PayPlus posts back to us.
 *
 * The card-update rail already had exactly this — under the name `wc_shop_token`,
 * on routes under `/woocommerce/`. Which meant a SHOPIFY shop charging through
 * PayPlus, doing the same vaulting through the same gateway, could not use the
 * flow at all: it had no `wc_shop_token`, and `CardUpdateService::availableFor()`
 * asked for one. The rail was never WooCommerce-specific; only its plumbing was.
 *
 * `callback_token` is that plumbing, renamed to what it is and given to every
 * shop. It is BACKFILLED FROM `wc_shop_token` where one exists, so every link
 * PayPlus already holds keeps resolving to the same shop — the old Woo routes
 * stay mounted as aliases for exactly that reason.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    /** Same shape as wc_shop_token: opaque, URL-safe, unguessable. */
    private const TOKEN_LENGTH = 40;

    public function up(): void
    {
        if (! Schema::hasColumn('shops', 'callback_token')) {
            Schema::table('shops', function (Blueprint $table): void {
                $table->string('callback_token', 64)->nullable()->unique()->after('wc_shop_token');
            });
        }

        // Carry the existing token over, then mint one for everybody else. Done
        // row by row because each value must be unique and unguessable — a
        // single UPDATE would give every shop the same token.
        foreach (DB::table('shops')->select('id', 'wc_shop_token')->orderBy('id')->cursor() as $shop) {
            $existing = trim((string) ($shop->wc_shop_token ?? ''));

            DB::table('shops')->where('id', $shop->id)->update([
                'callback_token' => $existing !== '' ? $existing : Str::random(self::TOKEN_LENGTH),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shops', 'callback_token')) {
            Schema::table('shops', function (Blueprint $table): void {
                $table->dropUnique(['callback_token']);
                $table->dropColumn('callback_token');
            });
        }
    }
};
