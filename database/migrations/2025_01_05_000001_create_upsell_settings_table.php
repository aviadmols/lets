<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shop, upsell-scoped preferences edited on the Post-Purchase Offers →
 * Settings tab (docs/ux/40 Tab 4). UI/merchant-preference storage ONLY — it does
 * not gate or alter the charge engine; the resolver/charge service own that. One
 * row per shop. Tenant-scoped (shop_id + BelongsToShop on the model).
 *
 * `partial_paid_handling` is the D2-ASK setting: how to treat a thank-you upsell
 * when the parent order is not yet fully paid (installment parent). Recommended
 * default = `do_nothing` (the child upsell order is independent, fully paid via
 * draft-completed-as-paid). `removal_window` is the grace window used when the
 * merchant chooses `remove_item`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upsell_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained('shops')->cascadeOnDelete();

            // do_nothing | remove_item  (UI radio on the Settings tab).
            $table->string('partial_paid_handling')->default('remove_item');
            // Window (hours) before the upsell item is removed from an unpaid order.
            $table->integer('removal_window')->default(24);

            // Global enable/disable of the thank-you widget for the shop.
            $table->boolean('enabled')->default(true);
            // Max offers shown per thank-you page.
            $table->integer('offer_display_cap')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upsell_settings');
    }
};
