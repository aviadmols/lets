<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timeline / audit feed. The human-facing view of everything that happened:
 * every charge, refund, state transition, email, webhook, and admin action is
 * recorded here as a typed event (kind + details JSON), scoped per shop and
 * cross-linked to a plan/payment. Append-only.
 *
 * Generalizes the reference engine's `payplus_installment_events` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();

            $table->string('actor')->default('system');   // system|admin:{id}|customer|webhook
            $table->string('kind')->index();              // e.g. charge_succeeded, reminder_email_sent
            $table->json('details')->nullable();

            $table->timestamp('created_at')->nullable();   // append-only: no updated_at

            $table->index(['shop_id', 'plan_id', 'created_at']);
            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
