<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The points ledger — append-only, exactly like payment_ledger, and for the same
 * reason: a balance you cannot reconstruct is a balance nobody can defend when a
 * customer asks "why do I have 300 points?".
 *
 * UNIQUE(shop_id, idempotency_key) is THE wall. Every grant names its cause
 * deterministically (earn:ledger:{id}, birthday:{account}:{year},
 * social:{account}:{key}, tier_entry:{account}:{tier}, redeem:{uuid}), so a
 * replayed webhook, a double-clicked button and a retried job all collapse onto
 * one row instead of minting points twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_point_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('loyalty_account_id')->constrained('loyalty_accounts')->cascadeOnDelete();

            $table->string('kind', 32);
            $table->integer('points'); // signed: earns positive, redemptions negative
            $table->decimal('amount', 12, 2)->nullable(); // the money that earned it, when there was any
            $table->unsignedBigInteger('source_ledger_id')->nullable();

            $table->string('idempotency_key');
            $table->json('meta')->nullable();

            // Append-only: no updated_at, nothing edits a recorded event.
            $table->timestamp('created_at')->nullable();

            $table->unique(['shop_id', 'idempotency_key']);
            $table->index(['shop_id', 'loyalty_account_id', 'created_at']);
            $table->index('source_ledger_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_events');
    }
};
