<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 * down() never guesses. Not CONCURRENTLY: both tables build in seconds at
 * today's size.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const EVENTS_INDEX = 'activity_events_shop_kind_created_idx';

    private const PLANS_INDEX = 'plans_shop_kind_status_idx';

    public function up(): void
    {
        Schema::table('activity_events', function (Blueprint $table): void {
            $table->index(['shop_id', 'kind', 'created_at'], self::EVENTS_INDEX);
        });

        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->index(['shop_id', 'plan_kind', 'status'], self::PLANS_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('activity_events', function (Blueprint $table): void {
            $table->dropIndex(self::EVENTS_INDEX);
        });

        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->dropIndex(self::PLANS_INDEX);
        });
    }
};
