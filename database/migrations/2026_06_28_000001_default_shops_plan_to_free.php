<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS billing-plan foundation: every shop is on the "free" tier now (owner's
 * locked decision — one plan, no charging yet). This sets the column DEFAULT to
 * 'free' so newly-installed shops are born on Free, and backfills any existing row
 * whose plan is NULL/blank to 'free'. ADD-only + idempotent: re-running can't lose
 * a paid plan a shop is already on (only NULL/'' rows are touched).
 *
 * The accessor Shop::billingPlan() also defaults to FREE in code, so this migration
 * is belt-and-suspenders, not the sole source of truth.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const DEFAULT_PLAN = 'free';

    public function up(): void
    {
        // Backfill existing NULL/blank plans to 'free' (tidy data; the accessor would
        // resolve them to FREE anyway).
        DB::table('shops')
            ->where(function ($q): void {
                $q->whereNull('plan')->orWhere('plan', '=', '');
            })
            ->update(['plan' => self::DEFAULT_PLAN]);

        // Set the column DEFAULT to 'free' so newly-installed shops are born on Free,
        // but keep it NULLABLE: Shop::billingPlan() already resolves null/blank/unknown
        // to FREE (fail-safe), so a NOT NULL constraint would only add insert friction
        // (e.g. an explicit ['plan' => null]) for no isolation/correctness gain.
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('plan')->default(self::DEFAULT_PLAN)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Revert to the original nullable, no-default column. The 'free' values
        // remain (harmless); only the schema constraint is rolled back.
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('plan')->default(null)->nullable()->change();
        });
    }
};
