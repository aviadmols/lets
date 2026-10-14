<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bundle can draw its products from a store CATEGORY instead of a hand-picked list,
 * and an offer can set its own tiles per row in the grid layout.
 *
 * `product_categories` links a cached Product to each upstream category it sits in
 * (WooCommerce product_cat today), written by the catalogue sync. A product in three
 * categories has three rows.
 *
 * On the offer: `bundle_source` is 'products' (the list in bundle_product_ids) or
 * 'category' (every active product linked to bundle_category_id, read when the card is
 * built). `bundle_category_name` keeps the label the merchant picked, so the builder
 * still names it after the store renames or empties it. `bundle_grid_columns` overrides
 * the shop's grid_columns for this offer only; null = the shop's setting.
 *
 * Additive: every existing offer keeps working exactly as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('external_category_id');
            $table->string('name');
            $table->timestamps();

            $table->unique(['shop_id', 'product_id', 'external_category_id']);
            $table->index(['shop_id', 'external_category_id']);
        });

        Schema::table('upsell_flow_offers', function (Blueprint $table) {
            $table->string('bundle_source')->default('products')->after('bundle_product_ids');
            $table->string('bundle_category_id')->nullable()->after('bundle_source');
            $table->string('bundle_category_name')->nullable()->after('bundle_category_id');
            $table->unsignedTinyInteger('bundle_grid_columns')->nullable()->after('bundle_columns');
        });
    }

    public function down(): void
    {
        Schema::table('upsell_flow_offers', function (Blueprint $table) {
            $table->dropColumn(['bundle_source', 'bundle_category_id', 'bundle_category_name', 'bundle_grid_columns']);
        });

        Schema::dropIfExists('product_categories');
    }
};
