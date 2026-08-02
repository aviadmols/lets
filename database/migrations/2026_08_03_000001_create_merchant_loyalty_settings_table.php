<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's loyalty program configuration — one row per shop, the sibling of
 * merchant_billing_settings / merchant_upsell_appearance.
 *
 * Every enum-ish column is a plain STRING validated in the model against a CONST
 * allow-list (house discipline: a DB enum turns a product decision into a
 * migration). Money-shaped knobs (points per currency unit, the redemption rate)
 * are clamped in the model the way MerchantBillingSettings clamps deposits — the
 * merchant sets the policy, the model refuses an impossible one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_loyalty_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained('shops')->cascadeOnDelete();

            // --- Program ---
            $table->boolean('enabled')->default(false);
            $table->string('program_name')->nullable();
            $table->unsignedInteger('points_per_currency')->default(1); // points per 1 currency unit, before the tier multiplier
            $table->string('rounding', 16)->default('floor');

            // --- Redemption (points → store credit) ---
            $table->unsignedInteger('redeem_rate_points')->default(100);
            $table->decimal('redeem_rate_amount', 10, 2)->default(10.00); // "100 points = 10.00"
            $table->unsignedInteger('min_redeem_points')->default(0);

            // --- Bonuses ---
            $table->unsignedInteger('join_bonus_points')->default(0);
            $table->unsignedInteger('birthday_points')->default(0);

            // Honor-based one-click actions: [{key,label,points,url}].
            $table->json('social_actions')->nullable();

            // --- Appearance of the customer-facing page ---
            $table->string('accent_color', 7)->default('#7746EC');
            $table->string('accent_text_color', 7)->default('#FFFFFF');
            $table->string('theme_mode', 16)->default('light');
            $table->string('corner_radius', 16)->default('soft');
            $table->string('page_locale', 8)->default('he');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_loyalty_settings');
    }
};
