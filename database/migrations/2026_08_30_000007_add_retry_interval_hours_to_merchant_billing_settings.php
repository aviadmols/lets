<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long we wait between asking again for a cycle that did not go through.
 *
 * The retry policy used to be a BACKOFF LADDER (4h, 24h, 72h) that the charge
 * engine never actually read — a merchant could set it and nothing changed.
 * The policy is now plain and honoured: one attempt a day, for a fixed number
 * of days, and then the cycle is skipped. Two numbers describe it — this one
 * and the attempt ceiling that already exists — so the screen can stop
 * describing a ladder nobody climbs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('retry_interval_hours')->default(24)->after('retry_backoff_hours');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->dropColumn('retry_interval_hours');
        });
    }
};
