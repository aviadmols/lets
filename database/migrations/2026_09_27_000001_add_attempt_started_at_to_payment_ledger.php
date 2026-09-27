<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the CURRENT attempt on a ledger row started.
 *
 * A declined cycle keeps one row through every retry (Ledger::open reopens it),
 * so the row's created_at is the FIRST attempt's time — days old by the third.
 * The orchestrator's in-flight wall judges a pending row by its age; judged by
 * created_at a reopened retry would look stuck the moment it started. The wall
 * reads this column instead (falling back to created_at for rows written before
 * it existed). Nullable and additive: old rows keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table): void {
            $table->timestamp('attempt_started_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table): void {
            $table->dropColumn('attempt_started_at');
        });
    }
};
