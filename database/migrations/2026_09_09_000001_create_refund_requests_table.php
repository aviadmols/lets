<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE row per "give this money back" — the thing a merchant actually clicked.
 *
 * A refund is not one action, it is three that fail independently: the money
 * leaves PayPlus, the store order has to say so, and a credit note has to be
 * issued. Until now each leg only left its own trace (a ledger row, nothing,
 * an issued_documents row), so a store call that failed AFTER the money moved
 * left nobody able to answer "what did I ask for, and where did it stop?".
 *
 * The row is written BEFORE any leg runs and is the retry handle afterwards.
 * `idempotency_key` is derived from what was asked (order + mode + amount +
 * lines + restock), so a double-clicked drawer resolves to the same request
 * instead of a second refund — the same law the ledger and the documents obey.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // WHAT is being refunded. The store order is the unit a merchant
            // thinks in; the plan is carried when one is involved.
            $table->string('platform', 16);
            $table->string('external_order_id', 191)->nullable();
            $table->foreignId('plan_id')->nullable()->constrained('installment_plans')->nullOnDelete();

            // The ONE charge the merchant picked, when they picked one. An order
            // is often several charges (a checkout plus an accepted upsell, a
            // plan's cycles) and "refund this cycle" is a different instruction
            // from "refund this order". Null = every refundable charge on it.
            $table->unsignedBigInteger('ledger_id')->nullable();

            // WHO asked. The actor vocabulary is ActivityEvent's (system /
            // customer / webhook / a merchant user), plus the user id when a
            // person in the admin clicked it.
            $table->string('requested_by', 32)->default('system');
            $table->unsignedBigInteger('user_id')->nullable();

            // cancel_order | refund_full | refund_partial
            $table->string('mode', 24);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 8)->default('ILS');

            // Per-line quantities, when the merchant chose items rather than a
            // sum. Null means "an amount, not a basket".
            $table->json('lines')->nullable();

            $table->boolean('restock')->default(false);
            $table->string('reason', 255)->nullable();
            $table->boolean('notify')->default(true);

            // pending → money_done → store_done → completed
            //         ↘ needs_attention (store failed AFTER money moved) ↗
            //         ↘ failed (nothing moved)
            $table->string('status', 24)->default('pending');

            // payplus | shopify_native | woo_gateway | external | none
            $table->string('money_rail', 24)->nullable();

            $table->json('money_result')->nullable();
            $table->json('store_result')->nullable();
            $table->json('doc_result')->nullable();
            $table->string('failure_code', 64)->nullable();

            $table->string('idempotency_key', 191);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'idempotency_key']);
            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'external_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
