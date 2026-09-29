<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this shop's store FIRST reported an order carrying its own site_url
 * (plugin 0.50.0+). From then on the store is known to run a build that sends
 * one, so an invoicing report WITHOUT a site_url is not from that store — it is
 * an old copy (staging, backup restore) holding the same key — and is refused.
 * Null for every shop until its store reports once: old builds keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_invoicing_settings', function (Blueprint $table): void {
            $table->timestamp('site_url_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_invoicing_settings', function (Blueprint $table): void {
            $table->dropColumn('site_url_seen_at');
        });
    }
};
