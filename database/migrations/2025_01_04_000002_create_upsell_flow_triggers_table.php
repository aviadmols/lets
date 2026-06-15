<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trigger rules for a flow. The UpsellResolver evaluates these against the
 * source purchase context (purchased product gids + order subtotal). A flow
 * fires when ANY of its triggers match (OR semantics) — `any_product` always
 * matches, the rest match on a specific product / collection / tag / minimum
 * order value. Tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upsell_flow_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('upsell_flows')->cascadeOnDelete();

            // any_product|specific_product|collection|tag|min_order_value
            $table->string('match_type');

            $table->string('shopify_product_gid')->nullable();
            $table->string('shopify_collection_gid')->nullable();
            $table->string('tag')->nullable();
            $table->decimal('min_order_value', 12, 2)->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'flow_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upsell_flow_triggers');
    }
};
