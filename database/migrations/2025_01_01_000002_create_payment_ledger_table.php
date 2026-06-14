<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable payment ledger. Every money movement is recorded here. No automatic
 * charge may happen without a ledger record; no PayPlus callback may update
 * state without resolving an existing ledger record. Masked raw responses only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('shopify_customer_id')->nullable();
            $table->string('shopify_order_id')->nullable();
            $table->string('parent_order_id')->nullable();
            $table->string('child_order_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('payment_method_id')->nullable();

            // deposit|installment|recurring|upsell|retry|manual
            $table->string('charge_context');
            $table->string('idempotency_key');
            $table->string('payplus_transaction_uid')->nullable();
            $table->string('payplus_document_uid')->nullable();

            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('ILS');

            // pending|succeeded|failed|refunded|cancelled|retry_scheduled
            $table->string('status')->default('pending');
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('raw_response_masked')->nullable();

            $table->timestamps();

            // A succeeded charge for an idempotency key must be unique per shop.
            $table->unique(['shop_id', 'idempotency_key']);
            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'plan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_ledger');
    }
};
