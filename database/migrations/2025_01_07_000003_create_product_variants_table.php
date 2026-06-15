<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-variant cache rows under a Product. Per-variant SKU + price live HERE
 * (plan templates target a specific variant, or all variants when the template's
 * product_variant_id is null). Tenant-scoped (shop_id NOT NULL). Unique
 * (shop_id, product_id, external_variant_id) keys the import upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Upstream variant id (Shopify numeric variant id / Woo variation id).
            $table->string('external_variant_id');
            $table->string('title')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->integer('position')->default(0);

            $table->timestamps();

            $table->unique(['shop_id', 'product_id', 'external_variant_id']);
            $table->index(['shop_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
