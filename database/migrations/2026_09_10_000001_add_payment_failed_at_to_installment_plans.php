<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "This subscription is on hold because we could not collect for it."
 *
 * A plan that runs out of retries is now PAUSED rather than rolled forward to
 * its next ordinary renewal — the cycle is still owed, not forgiven. But paused
 * already means something else on this table: the customer asked us to stop.
 * The two need telling apart, because only one of them belongs on the merchant's
 * home screen, keeps its "Charge now" button, and resumes itself the moment the
 * money lands.
 *
 * So the stamp, not a status: the DATE collection gave up, which is also what
 * the home screen sorts by and what tells a merchant how long somebody has been
 * unpaid. Indexed because the home screen asks "who is unpaid?" on every load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->timestamp('payment_failed_at')->nullable()->after('last_charge_attempt_at');
            $table->index(['shop_id', 'payment_failed_at'], 'plans_shop_payment_failed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->dropIndex('plans_shop_payment_failed_idx');
            $table->dropColumn('payment_failed_at');
        });
    }
};
