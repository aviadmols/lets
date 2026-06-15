<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The OFFER a flow presents: a product/variant, an optional discount, and the
 * customer-facing copy (headline/subcopy/CTAs). Copy is stored as raw text and
 * rendered through __() lang keys when the merchant uses a known key, or shown
 * verbatim when they type custom text. `position` orders multiple offers inside
 * one flow (first shown first). Tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upsell_flow_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('upsell_flows')->cascadeOnDelete();

            $table->string('offer_product_gid');
            $table->string('offer_variant_gid');
            // For child-order line + display; the source of money truth.
            $table->string('offer_title')->nullable();
            $table->decimal('base_price', 12, 2)->default(0);

            // none|percent|fixed
            $table->string('discount_type')->default('none');
            $table->decimal('discount_value', 12, 2)->default(0);

            // Customer-facing copy (i18n-able text or a lang key).
            $table->string('headline')->nullable();
            $table->text('subcopy')->nullable();
            $table->string('accept_cta')->nullable();
            $table->string('decline_cta')->nullable();

            $table->integer('position')->default(0);

            $table->timestamps();

            $table->index(['shop_id', 'flow_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upsell_flow_offers');
    }
};
