<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per billing attempt WE asked Shopify to make — the audit trail of the
 * app-driven billing loop (Shopify does not auto-bill; the app schedules).
 *
 * Deliberately NOT payment_ledger: that table's invariant is "we charged a
 * PayPlus token and recorded the money truth". Here Shopify processes the payment
 * and owns the truth; this table records only that we REQUESTED an attempt, for
 * which cycle, and what Shopify reported back — so the scanner is idempotent
 * (one attempt per contract+cycle) and the merchant screen can show the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_billing_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('subscription_contract_id')
                ->constrained('subscription_contracts')->cascadeOnDelete();

            // The cycle this attempt bills, as Shopify identifies it (the billing
            // date the cycle covers). The UNIQUE below is the double-billing wall:
            // a re-run scanner or a redelivered job reuses the row, never re-asks.
            $table->string('billing_cycle_key', 32);

            // Our idempotency key, ALSO sent to Shopify as the mutation's
            // idempotencyKey — so even a crash between INSERT and the API call
            // cannot produce two attempts for one cycle.
            $table->string('idempotency_key');

            // requested | succeeded | failed | challenged
            $table->string('status', 16)->default('requested');

            $table->string('shopify_attempt_gid')->nullable();
            $table->string('shopify_order_gid')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'subscription_contract_id', 'billing_cycle_key'], 'sba_cycle_unique');
            $table->unique(['shop_id', 'idempotency_key']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_billing_attempts');
    }
};
