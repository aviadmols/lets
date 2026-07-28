<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A GIFT CAMPAIGN: "every subscriber with at least N charged cycles gets product X,
 * shipped free."
 *
 * The product title, unit price and currency are SNAPSHOTS taken when the merchant
 * generates. A campaign is a historical record of what was given away — re-reading
 * today's catalog price a month later would silently rewrite what the gift was
 * worth, and the store orders it created would stop agreeing with it.
 *
 * No money moves here: nothing is charged, so there is no ledger row and no
 * accounting document. The campaign only creates zero-total store orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->string('title');

            // The threshold: how many SUCCESSFULLY CHARGED cycles qualify a subscriber.
            $table->unsignedInteger('min_cycles')->default(1);

            // The gift, as picked from the local catalog cache. Nulled rather than
            // cascaded if the product is later deleted — the snapshot below is what
            // the campaign actually gave away, and it must survive the catalog.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            // Snapshots — what was given, and what it was worth, at generation time.
            $table->string('product_title')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->char('currency', 3)->default('ILS');

            // Printed on the order as the shipping method's name.
            $table->string('shipping_label')->nullable();

            // draft | generating | completed | completed_with_errors
            $table->string('status', 24)->default('draft');
            $table->timestamp('generated_at')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_campaigns');
    }
};
