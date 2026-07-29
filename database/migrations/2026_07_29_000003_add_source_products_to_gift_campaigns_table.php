<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narrow a campaign to the subscribers of particular products.
 *
 * "Everyone past N cycles" is one rule; "everyone past N cycles who subscribes to
 * the coffee box" is the one a merchant actually wants when the gift is a coffee
 * mug. Empty (null) keeps the original meaning — every product.
 *
 * Local Product ids, not platform ids: they survive a re-sync that changes nothing
 * the merchant chose, and the picker already speaks them. The external ids the
 * subscription rows carry are resolved at query time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_campaigns', function (Blueprint $table): void {
            $table->json('source_product_ids')->nullable()->after('min_cycles');
        });
    }

    public function down(): void
    {
        Schema::table('gift_campaigns', function (Blueprint $table): void {
            $table->dropColumn('source_product_ids');
        });
    }
};
