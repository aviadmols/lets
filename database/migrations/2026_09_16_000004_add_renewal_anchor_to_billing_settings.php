<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What a renewal counts from once a charge lands LATE.
 *
 * `cycle`: every cycle on the schedule is owed. A subscriber whose card was dead
 * for three months collects all three once it is fixed, one a day, and keeps the
 * anniversary. That is what every shop does today, so it is the default and an
 * upgrade changes nothing.
 *
 * `charge_date`: the customer pays for the cycle being charged and the next one
 * is a cycle from today. Months they got nothing for are not collected; the
 * anniversary moves to the day the card worked. What Recharge does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->string('renewal_anchor', 16)->default('cycle')->after('recurring_charge_description');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->dropColumn('renewal_anchor');
        });
    }
};
