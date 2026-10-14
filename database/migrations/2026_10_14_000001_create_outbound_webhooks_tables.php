<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound webhooks — a merchant points LETS at their own URL (Make, Zapier, n8n,
 * their ERP) and we POST a signed JSON for every order paid and every recurring
 * charge that succeeded or failed.
 *
 *   webhook_endpoints  — where to send + which topics + the signing secret
 *                        (encrypted at rest).
 *   webhook_deliveries — one row per (endpoint, event): the payload we sent, the
 *                        answer we got, the attempt count. Unique on
 *                        (endpoint, event_key), so a replayed event (a webhook
 *                        delivered twice, a retried job) collapses onto one row
 *                        instead of POSTing the same charge twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('name');
            $table->string('url', 2048);
            $table->text('secret');
            $table->json('topics');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('topic', 64);
            $table->string('event_key');
            $table->json('source')->nullable();
            $table->json('payload')->nullable();
            $table->boolean('is_test')->default(false);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['webhook_endpoint_id', 'event_key']);
            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
