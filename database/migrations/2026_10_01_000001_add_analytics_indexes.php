<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two indexes docs/analytics/data-map.md promises the Analytics module.
 *
 *   activity_events (shop_id, kind, created_at) — the movement log, the
 *     cancellation log and the payment journeys all ask "this shop's events of
 *     THESE kinds since T". Until now that meant (shop_id, created_at) plus a
 *     filter over every kind the Timeline writes (emails, webhooks, edits…).
 *   installment_plans (shop_id, plan_kind, status) — the active-book union and
 *     the risk / forecast scans read one plan kind in a few statuses.
 *
 * Additive only: neither replaces an existing index (activity_events keeps its
 * (shop_id, created_at) and kind indexes; installment_plans keeps the
 * scheduler's (shop_id, status, next_charge_at)). Names are explicit so the
 * down() never guesses.
 *
 * On Postgres the indexes build CONCURRENTLY: every charge and webhook writes an
 * activity_events row, and a plain CREATE INDEX would hold those writes for the
 * whole build. CONCURRENTLY cannot run inside a transaction, so this migration
 * opts out of the wrapping one. SQLite (tests) builds them plainly.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    /** CREATE INDEX CONCURRENTLY refuses to run inside a transaction. */
    public $withinTransaction = false;

    private const EVENTS_INDEX = 'activity_events_shop_kind_created_idx';

    private const PLANS_INDEX = 'plans_shop_kind_status_idx';

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::EVENTS_INDEX.' ON activity_events (shop_id, kind, created_at)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::PLANS_INDEX.' ON installment_plans (shop_id, plan_kind, status)');

            return;
        }

        Schema::table('activity_events', function (Blueprint $table): void {
            $table->index(['shop_id', 'kind', 'created_at'], self::EVENTS_INDEX);
        });

        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->index(['shop_id', 'plan_kind', 'status'], self::PLANS_INDEX);
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::EVENTS_INDEX);
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::PLANS_INDEX);

            return;
        }

        Schema::table('activity_events', function (Blueprint $table): void {
            $table->dropIndex(self::EVENTS_INDEX);
        });

        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->dropIndex(self::PLANS_INDEX);
        });
    }
};
