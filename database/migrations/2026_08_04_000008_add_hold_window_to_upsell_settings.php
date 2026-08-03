<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long an order waits while the shopper decides whether to add something.
 *
 * Zero — the default — means no hold at all, which is what every existing shop
 * gets. Holding a paid order is a real cost to the merchant (later dispatch,
 * later delivery, a support question), so it is opt-in and never a side effect
 * of installing the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upsell_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('hold_window_minutes')->default(0);
            // Whether to email the shopper when the window closes on an order
            // they actually added to. Off means Shopify's own confirmation is
            // the last word.
            $table->boolean('hold_notify')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('upsell_settings', function (Blueprint $table) {
            $table->dropColumn(['hold_window_minutes', 'hold_notify']);
        });
    }
};
