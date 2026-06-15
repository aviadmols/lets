<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `platform` to shops — the discriminator the ProductSourceFactory switches
 * on (shopify now, woocommerce Stage 2). Defaults to 'shopify' so existing rows
 * are correct without a backfill. Lands BEFORE the products tables (000002+),
 * which is fine: `platform` lives on shops, not on products.
 *
 * Stage-2 note: WooCommerce shops will also carry per-shop encrypted consumer
 * key/secret — those go in the existing encrypted credential bag (or a dedicated
 * cast) when WooCommerceProductSource is wired live; no schema change is needed
 * here for the abstraction to stand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('platform')->default('shopify')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
