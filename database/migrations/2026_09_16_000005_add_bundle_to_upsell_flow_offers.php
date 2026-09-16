<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A post-purchase offer can be a BUNDLE: the merchant lists several products, the
 * shopper picks exactly `bundle_quantity` of them, and all of them are charged at the
 * one `bundle_price` (product_selection_mode = 'bundle').
 *
 * `bundle_product_ids` holds LOCAL catalog Product ids, in the merchant's order.
 * `bundle_columns` is how many sit side by side on one slide of the storefront slider.
 *
 * Additive and nullable: every existing offer keeps working exactly as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upsell_flow_offers', function (Blueprint $table) {
            $table->json('bundle_product_ids')->nullable()->after('offer_variant_gid');
            $table->unsignedTinyInteger('bundle_quantity')->nullable()->after('bundle_product_ids');
            $table->decimal('bundle_price', 12, 2)->nullable()->after('bundle_quantity');
            $table->unsignedTinyInteger('bundle_columns')->default(1)->after('bundle_price');
        });
    }

    public function down(): void
    {
        Schema::table('upsell_flow_offers', function (Blueprint $table) {
            $table->dropColumn(['bundle_product_ids', 'bundle_quantity', 'bundle_price', 'bundle_columns']);
        });
    }
};
