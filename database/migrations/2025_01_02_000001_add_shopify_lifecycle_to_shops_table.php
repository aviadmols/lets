<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify OAuth + lifecycle columns. The offline access token (encrypted) is set
 * at install; installed_at / uninstalled_at track the install lifecycle so the
 * scheduler can gate due-charge dispatch on shop.status (uninstalled shops are
 * skipped — their token is revoked, every API call would 401).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->timestamp('installed_at')->nullable()->after('status');
            $table->timestamp('uninstalled_at')->nullable()->after('installed_at');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['installed_at', 'uninstalled_at']);
        });
    }
};
