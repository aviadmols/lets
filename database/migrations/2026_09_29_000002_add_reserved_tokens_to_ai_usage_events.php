<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens RESERVED for a call still in flight (AiGateway reserve-then-call).
 *
 * The gateway writes the call's row BEFORE asking the provider, holding the
 * estimated cost here, under a lock — so two parallel calls cannot both pass a
 * budget only one of them fits in. The row is settled with the real usage after
 * the call (reserved back to 0). A worker that dies mid-call leaves its
 * reservation counted for the rest of the day: the safe direction for a budget.
 * Additive; existing rows read as 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $table): void {
            $table->unsignedInteger('reserved_tokens')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $table): void {
            $table->dropColumn('reserved_tokens');
        });
    }
};
