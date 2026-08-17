<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "do not charge live" switch — a migration's safety net.
 *
 * A store moving thousands of subscribers in needs its plans ACTIVE (so the
 * merchant can read them, so customers see them, so the dates are visible and
 * checkable) long before it wants a single saved card touched. Until now those
 * two things were the same act: giving a plan a date IS what makes it chargeable.
 *
 * The alternative already in the code — releasing with no date at all — blocks
 * charging by construction but is a one-way door: the release clears the import's
 * hold marker, so nothing in the app can find those plans again to schedule them.
 *
 * Defaults to TRUE. A shop that never touches this screen charges exactly as it
 * did before; the column only exists so a merchant can say "not yet".
 *
 * `charging_paused_at` is not decoration. Dates keep passing while the switch is
 * off, so turning it back on has to roll the overdue ones forward or every one of
 * them fires at once — and knowing WHEN it was switched off is what makes that
 * decision explainable to the merchant.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TABLE = 'merchant_billing_settings';

    private const ENABLED = 'live_charging_enabled';

    private const PAUSED_AT = 'charging_paused_at';

    private const AFTER = 'single_active_subscription';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->boolean(self::ENABLED)->default(true)->after(self::AFTER);
            $table->timestamp(self::PAUSED_AT)->nullable()->after(self::ENABLED);
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn([self::ENABLED, self::PAUSED_AT]);
        });
    }
};
