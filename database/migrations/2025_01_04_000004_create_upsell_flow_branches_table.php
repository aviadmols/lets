<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branching: after a customer ACCEPTS or DECLINES an offer, which offer (if any)
 * comes next. Null = end of the flow. This is the Flow-Builder canvas's edge
 * model (admin-design-system renders it; we resolve it). Tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upsell_flow_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('upsell_flows')->cascadeOnDelete();

            $table->foreignId('from_offer_id')->constrained('upsell_flow_offers')->cascadeOnDelete();
            // Nullable next-offer pointers (null = flow ends on that path).
            $table->unsignedBigInteger('on_accept_next_offer_id')->nullable();
            $table->unsignedBigInteger('on_decline_next_offer_id')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'flow_id']);
            $table->index(['shop_id', 'from_offer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upsell_flow_branches');
    }
};
