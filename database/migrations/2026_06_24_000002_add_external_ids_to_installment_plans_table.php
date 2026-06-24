<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W11 Phase 0 — platform-neutral external id columns on `installment_plans`. All
 * nullable + additive (existing Shopify plans keep using shopify_* unchanged). A
 * WooCommerce plan stores its WC ids here; InstallmentPlan::externalOrderId() reads
 * external_order_id ?? shopify_order_id so both platforms resolve uniformly without
 * dropping/renaming the legacy shopify_* columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->string('external_order_id')->nullable();
            $table->string('external_customer_id')->nullable();
            $table->string('external_variant_id')->nullable();
            $table->string('external_product_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->dropColumn([
                'external_order_id',
                'external_customer_id',
                'external_variant_id',
                'external_product_id',
            ]);
        });
    }
};
