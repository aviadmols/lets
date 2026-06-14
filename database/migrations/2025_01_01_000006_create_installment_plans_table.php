<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription plans. plan_kind discriminates installments-until-paid vs.
 * open-ended recurring. Tenant-scoped (shop_id NOT NULL). The composite
 * (shop_id, status, next_charge_at) index powers the scheduler's due-charge fan
 * out so cost is O(due-today), not O(all-plans).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('shopify_customer_id')->nullable();
            $table->string('shopify_order_id')->nullable();

            // The saved-token vault row this plan charges against (nullable for
            // manual-payment plans that email an invoice instead).
            $table->foreignId('payment_method_id')->nullable()
                ->constrained('installment_payment_methods')->nullOnDelete();

            // installments | recurring
            $table->string('plan_kind')->default('installments');
            // deposit|installment|recurring — the default context for this plan's charges
            $table->string('charge_context')->nullable();

            // draft|awaiting_first_payment|active|paused|failed|completed|cancelled
            $table->string('status')->default('draft');

            // Money (installments use total/charged/remaining; recurring uses installment_amount per cycle).
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('total_charged', 12, 2)->default(0);
            $table->decimal('installment_amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('ILS');

            // Recurring cadence.
            $table->string('billing_frequency')->nullable();   // BillingFrequency enum value
            $table->unsignedInteger('interval_count')->default(1);

            // Scheduling.
            $table->timestamp('next_charge_at')->nullable();
            $table->timestamp('last_charge_attempt_at')->nullable();

            // Manual-payment mode (email an invoice; no saved token).
            $table->boolean('requires_manual_payment')->default(false);

            $table->json('meta')->nullable();   // manual_payment_sent_at, fulfillment lock, etc.

            $table->timestamps();

            // Hot path: scheduler scans due plans per shop+status by next_charge_at.
            $table->index(['shop_id', 'status', 'next_charge_at']);
            $table->index(['shop_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_plans');
    }
};
