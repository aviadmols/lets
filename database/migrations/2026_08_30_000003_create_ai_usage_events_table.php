<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every call the platform makes to an AI provider — win or lose.
 *
 * The bill, the budget and the debugging all read from here: the daily cap is
 * a SUM over today's rows, "which shop is burning tokens" is a group-by, and a
 * failed call leaves the same dated evidence a successful one does (recording
 * only successes is how an outage becomes invisible in the ledger). Rows are
 * immutable — created_at only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('email_campaign_id')->nullable()->constrained('email_campaigns')->nullOnDelete();

            $table->string('stage', 40);
            $table->string('provider', 24);
            $table->string('model', 64);

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);

            // ok | failed | refused | over_budget
            $table->string('status', 24);
            $table->string('failure_reason', 64)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['shop_id', 'created_at']);  // per-shop usage + future per-shop budgets
            $table->index('created_at');               // the platform's daily budget scan
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
