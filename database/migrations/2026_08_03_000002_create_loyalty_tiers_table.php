<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The merchant's membership tiers (Spark / Glow / Shine, or whatever they name
 * them). A tier is a THRESHOLD on lifetime spend plus what it earns: a points
 * multiplier, a one-time entry bonus, and the perk lines the customer-facing
 * comparison table renders.
 *
 * min_spend is the only ordering that matters — the resolver picks the highest
 * tier the customer has passed — so `position` exists purely for the admin list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->string('name');
            $table->string('color', 7)->default('#7746EC');
            $table->string('icon', 32)->default('spark');
            $table->decimal('min_spend', 12, 2)->default(0);
            $table->decimal('points_multiplier', 5, 2)->default(1.00);
            $table->unsignedInteger('entry_bonus_points')->default(0);

            // Free-text perk lines; the comparison table is their union across tiers.
            $table->json('perks')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['shop_id', 'min_spend']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};
