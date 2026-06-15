<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local products cache. A source-agnostic mirror of the merchant's catalog
 * (Shopify now, WooCommerce later) so the Products screen + plan templates work
 * without an upstream round-trip on every render. Tenant-scoped (shop_id NOT
 * NULL). `source` + `external_id` identify the upstream record; the unique
 * (shop_id, source, external_id) prevents cross-shop clobber and lets the import
 * job upsert idempotently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // shopify | woocommerce — which upstream this row mirrors.
            $table->string('source')->default('shopify');
            // The upstream id (Shopify numeric product id / Woo product id). Stored
            // as a string so a GID-derived numeric or an opaque id both fit.
            $table->string('external_id');

            $table->string('title');
            $table->string('handle')->nullable();
            $table->string('image_url')->nullable();

            // active | draft | unlisted (unlisted = soft-deleted upstream; plans kept).
            $table->string('status')->default('active');
            // published | unpublished — the Online Store sales-channel state.
            $table->string('online_store_status')->default('unpublished');

            $table->json('tags')->nullable();

            // Upstream's own updated_at (for staleness checks) + when we last synced.
            $table->timestamp('updated_at_external')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // One row per upstream product per shop; the import upsert key.
            $table->unique(['shop_id', 'source', 'external_id']);
            // List filters by status; refresh queries find stale rows by updated_at.
            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'updated_at_external']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
