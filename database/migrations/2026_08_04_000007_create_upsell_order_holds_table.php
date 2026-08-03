<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order-hold work queue: which orders are waiting out their add-on window,
 * and when each one is due to be let go.
 *
 * A ROW, not a delayed job. A delayed job cannot be re-timed when the merchant
 * shortens the window, cannot be inspected when an order looks stuck, and — the
 * one that matters — a lost Redis key would strand a paid order held forever
 * with nothing recording that it was ever held. A scanner over this table is
 * self-healing: the worst a missed run costs is a late release.
 *
 * `hold_refs` is what has to be handed back to the platform to undo the hold
 * (Shopify's fulfillment-order hold ids). Recording them at hold time means a
 * release does not depend on re-deriving the same set later, when the order may
 * have changed shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upsell_order_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->string('platform', 20);            // shopify | woocommerce
            $table->string('external_order_id');
            $table->string('status', 20)->default('held'); // held | released | skipped

            $table->timestamp('release_at');
            $table->timestamp('released_at')->nullable();

            $table->json('hold_refs')->nullable();     // what to hand back to undo it
            $table->json('added_items')->nullable();   // what the shopper added, if anything
            $table->timestamp('notified_at')->nullable();
            $table->string('skip_reason', 60)->nullable();

            $table->timestamps();

            // One hold per order. A second impression on the same order must
            // extend nothing and create nothing — the window started at the first.
            $table->unique(['shop_id', 'external_order_id'], 'upsell_holds_order_unq');
            $table->index(['status', 'release_at'], 'upsell_holds_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upsell_order_holds');
    }
};
