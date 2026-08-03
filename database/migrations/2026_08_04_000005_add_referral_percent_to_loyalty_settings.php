<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "The sharer gets 5% of what they referred, as points."
 *
 * The two existing referral rewards are a flat points award and a points-per-
 * currency rate. Neither reads as a percentage: a merchant who wants 5% back has
 * to work out how many points a shekel is worth and set the rate to match, and
 * then redo that arithmetic every time they change their redemption rate.
 *
 * This column states the intent directly. MerchantLoyaltySettings converts it to
 * points through the merchant's OWN redemption rate, so 5% really is 5% of the
 * money — and stays 5% when the rate changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_loyalty_settings', function (Blueprint $table) {
            $table->decimal('referral_referrer_percent', 5, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('merchant_loyalty_settings', function (Blueprint $table) {
            $table->dropColumn('referral_referrer_percent');
        });
    }
};
