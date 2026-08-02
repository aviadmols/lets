<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template pricing modes + the intro-discount window.
 *
 * pricing_mode decides where a customer plan's per-cycle amount comes from:
 * 'plan_price' (catalog × template discount — the historical behavior and the
 * default), 'keep_first_payment' (the amount actually paid at checkout is kept
 * for every cycle), or 'fixed_amount' (the merchant-entered fixed_cycle_amount).
 * discount_cycles limits the template discount to the first N charges counting
 * the checkout as charge #1 — null keeps the discount forever (historical
 * behavior). All defaults preserve existing rows exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_subscription_plans', function (Blueprint $table): void {
            $table->string('pricing_mode', 32)->default('plan_price')->after('discount_value');
            $table->decimal('fixed_cycle_amount', 12, 2)->nullable()->after('pricing_mode');
            $table->unsignedSmallInteger('discount_cycles')->nullable()->after('fixed_cycle_amount');
        });
    }

    public function down(): void
    {
        Schema::table('product_subscription_plans', function (Blueprint $table): void {
            $table->dropColumn(['pricing_mode', 'fixed_cycle_amount', 'discount_cycles']);
        });
    }
};
