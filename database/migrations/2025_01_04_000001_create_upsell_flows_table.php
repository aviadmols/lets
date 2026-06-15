<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-purchase / thank-you-page upsell FLOWS (the third product pillar). A flow
 * is a named, prioritised container: its triggers decide WHEN it fires, its
 * offers decide WHAT is shown, its branches decide what comes next on
 * accept/decline. Tenant-scoped (shop_id + BelongsToShop). Lower priority =
 * evaluated first; the first active flow whose triggers match wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upsell_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->string('name');
            // active|inactive|draft — guarded on the model.
            $table->string('status')->default('draft');
            // Lower = evaluated first. Ties broken by id (older first).
            $table->integer('priority')->default(100);

            $table->timestamps();

            // The hot path: "active flows for this shop, by priority".
            $table->index(['shop_id', 'status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upsell_flows');
    }
};
