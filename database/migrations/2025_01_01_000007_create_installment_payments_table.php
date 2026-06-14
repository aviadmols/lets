<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual charge slots within a plan (deposit / installment-by-sequence /
 * recurring cycle). Tenant-scoped. The (shop_id, plan_id, sequence) unique index
 * makes findOrCreatePayment idempotent per sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('installment_plans')->cascadeOnDelete();

            // deposit|installment|recurring
            $table->string('payment_type')->default('installment');
            // 1-based position within the plan (the recurring cycle index for recurring plans).
            $table->unsignedInteger('sequence')->default(1);

            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('ILS');

            // pending|succeeded|failed|retry_scheduled|refunded
            $table->string('status')->default('pending');

            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('charged_at')->nullable();

            $table->string('payplus_transaction_uid')->nullable();
            $table->string('approval_number')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('raw_response_masked')->nullable();

            $table->timestamps();

            // One slot per (plan, sequence) — the idempotent payment finder.
            $table->unique(['shop_id', 'plan_id', 'sequence']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payments');
    }
};
