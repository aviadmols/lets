<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing SNAPSHOT on the per-customer plan.
 *
 * A customer consented to a specific price schedule at checkout, so the plan
 * carries its own copy of the template's pricing config — a later template edit
 * must never retroactively reprice a live plan. installment_amount stays the
 * (possibly discounted) steady-state cycle amount; regular_amount is the
 * undiscounted per-cycle amount (quantity included) the price steps UP to when
 * the intro window (discount_cycles) ends, and is what the discount-tag
 * predicate compares against. product_subscription_plan_id is provenance/display
 * only — money never reads through it. All columns nullable: a legacy plan
 * behaves exactly as before (resolver falls through to installment_amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->string('pricing_mode', 32)->nullable()->after('installment_amount');
            $table->unsignedSmallInteger('discount_cycles')->nullable()->after('pricing_mode');
            $table->decimal('regular_amount', 12, 2)->nullable()->after('discount_cycles');
            $table->foreignId('product_subscription_plan_id')
                ->nullable()
                ->after('regular_amount')
                ->constrained('product_subscription_plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_subscription_plan_id');
            $table->dropColumn(['pricing_mode', 'discount_cycles', 'regular_amount']);
        });
    }
};
