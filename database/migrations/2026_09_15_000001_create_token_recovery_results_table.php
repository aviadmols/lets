<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_recovery_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('token_recovery_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('plan_id')->index();

            // The bucket the run counted this member in (fixed, already_valid,
            // not_found, …) and the precise reason underneath it.
            $table->string('outcome', 32);
            $table->string('detail', 64)->nullable();

            /*
             * EVERY card PayPlus held for this member when we asked — token uid,
             * last 4, expiry, whether it had expired, when it was vaulted, and
             * which one we were holding.
             *
             * Stored because the counters could not answer the question a merchant
             * actually asks: "PayPlus shows two cards for this person, why did you
             * say there was nothing?" Without the list, the only way to find out
             * was to open PayPlus and compare by hand.
             *
             * It is also what the merchant CHOOSES from when the rules refuse to
             * decide between two live cards.
             */
            $table->json('candidates')->nullable();

            $table->timestamps();

            // "What happened to this member, most recently?"
            $table->index(['shop_id', 'plan_id', 'id']);
            // "Show me everything this run found."
            $table->index(['run_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_recovery_results');
    }
};
