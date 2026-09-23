<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "This subscriber pays nothing — never ask them for money."
 *
 * A comped member, a staff subscription, a gift, someone whose money is
 * collected somewhere else. The admin can now type one in (ManualSubscriptionService),
 * and the first version of that feature expressed "free" as a SHAPE: no charge
 * date, no card, no consent row. Every one of those came back off an ordinary
 * click — Resume mints a clock for a plan that has none, "Edit next charge" hands
 * one over, a card-update link attaches a card, and the consent gate matches the
 * CUSTOMER rather than the plan, so comping a person who already subscribes
 * inherits the consent they gave for their paid subscription.
 *
 * A promise the engine cannot read is not a promise. So: a column, which the
 * scheduler filters on, the orchestrator refuses on, and the revenue report
 * excludes — one fact, in the one place every path already looks.
 *
 * Indexed with (shop_id, status, next_charge_at) — the scheduler's own index —
 * because the due-plan scan now carries this predicate on every run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->boolean('no_charge')->default(false)->after('requires_manual_payment');
            $table->index(['shop_id', 'no_charge'], 'plans_shop_no_charge_idx');
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->dropIndex('plans_shop_no_charge_idx');
            $table->dropColumn('no_charge');
        });
    }
};
