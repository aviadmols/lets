<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two widenings of a gift campaign, both nullable so every existing row keeps
 * meaning exactly what it meant:
 *
 * - `source_emails`: the campaign narrowed to SPECIFIC people. NULL = everyone
 *   who meets the rule, which is what every campaign meant before.
 * - `items`: the gift as a LIST of products — [{product_id, product_variant_id,
 *   title, unit_price}], snapshots like the single-product columns they extend.
 *   NULL = the legacy single-product columns are the whole gift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_campaigns', function (Blueprint $table): void {
            $table->json('source_emails')->nullable()->after('source_product_ids');
            $table->json('items')->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('gift_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['source_emails', 'items']);
        });
    }
};
