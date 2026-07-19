<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flow Builder canvas layout: a per-flow map of node id → {x, y} so the merchant's
 * drag-and-drop node arrangement (Shopify-Flow style) survives a reload. Purely
 * presentational — the charge engine never reads it; a null layout = auto-laid-out
 * defaults. Nullable + additive, so existing flows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upsell_flows', function (Blueprint $table) {
            $table->json('layout')->nullable()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('upsell_flows', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
