<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The after-purchase offers' own kill switch.
 *
 * The live-charging switch deliberately does NOT stop an upsell (a shopper one
 * click into a purchase is not a migration being held). So a merchant who wants
 * saved cards charged by NOTHING had no way to say it. This is that way: off, no
 * offer is shown and no accept charges. TRUE by default — behaviour unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->boolean('upsell_charging_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->dropColumn('upsell_charging_enabled');
        });
    }
};
