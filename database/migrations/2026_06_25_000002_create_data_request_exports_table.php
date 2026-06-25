<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GDPR data-request exports (Shopify `customers/data_request`). When a customer
 * asks a merchant for their data, Shopify requires we make that data AVAILABLE to
 * the merchant within 30 days — we compile everything we hold for the customer
 * into a structured JSON document the merchant can retrieve and hand over.
 *
 * Tenant-scoped (shop_id NOT NULL + BelongsToShop): one shop can NEVER read
 * another shop's export. Idempotent per Shopify `data_request.id`: a re-delivered
 * webhook updates the SAME row (unique on shop_id + data_request_id) rather than
 * producing a duplicate export.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_request_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // Shopify's data_request.id — the idempotency anchor for this export.
            $table->string('data_request_id')->nullable();

            // Customer reference (the merchant resolves the human from these).
            $table->string('shopify_customer_id')->nullable();
            $table->string('customer_email')->nullable();

            // The compiled export document: plans, payments, consents, timeline.
            $table->json('export')->nullable();

            // received → fulfilled. Compiled exports are born `fulfilled` (we build
            // the document synchronously in the job); `received` exists for a future
            // async-fulfilment mode without a migration change.
            $table->string('status')->default('fulfilled');

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();

            $table->timestamps();

            // Idempotency: one export per shop per Shopify data-request id.
            $table->unique(['shop_id', 'data_request_id'], 'data_request_exports_dedupe_unique');
            $table->index(['shop_id', 'shopify_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_request_exports');
    }
};
