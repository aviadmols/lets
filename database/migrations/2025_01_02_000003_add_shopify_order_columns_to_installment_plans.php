<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify order-strategy columns. The order strategy (per charge_context)
 * materialises Shopify state AFTER a succeeded ledger row: the installments
 * PARENT order (gid + lock metafields) and recurring per-cycle orders link back
 * to the plan via these ids. customer_email / shopify_variant_id / public_id are
 * the minimal fields the ported ShopifyOrderCreator needs to build a line item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->string('shopify_order_gid')->nullable()->after('shopify_order_id');
            $table->string('shopify_variant_id')->nullable()->after('shopify_order_gid');
            $table->string('shopify_product_id')->nullable()->after('shopify_variant_id');
            $table->string('public_id')->nullable()->after('shopify_product_id')->index();
            $table->string('customer_email')->nullable()->after('public_id');
            $table->string('customer_name')->nullable()->after('customer_email');
            $table->string('customer_phone')->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->dropColumn([
                'shopify_order_gid', 'shopify_variant_id', 'shopify_product_id',
                'public_id', 'customer_email', 'customer_name', 'customer_phone',
            ]);
        });
    }
};
