<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offer-configuration columns for the "Configure cross-sell" drawer (docs/ux/40,
 * Recharge post-purchase reference). UI/display config ONLY — the charge engine
 * (UpsellChargeService) is untouched; money truth stays on the existing
 * discount_type/discount_value/base_price. These mirror the Recharge drawer's
 * fields 1:1 so the merchant's authoring choices persist tenant-scoped.
 *
 *   product_selection_mode  smart_select|specific   (which product to offer)
 *   variant_selection_mode  customer|merchant       (who picks the variant)
 *   purchase_option         one_time|subscription|subscription_only
 *   apply_discount_on_top   bool   (stack with Subscribe & Save)
 *   shipping_fee_mode       free|charge
 *   show_timer              bool   (countdown urgency)
 *   timer_minutes           ?int   (countdown length when shown)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upsell_flow_offers', function (Blueprint $table) {
            $table->string('product_selection_mode')->default('specific')->after('offer_variant_gid');
            $table->string('variant_selection_mode')->default('customer')->after('product_selection_mode');
            $table->string('purchase_option')->default('one_time')->after('variant_selection_mode');
            $table->boolean('apply_discount_on_top')->default(false)->after('discount_value');
            $table->string('shipping_fee_mode')->default('free')->after('apply_discount_on_top');
            $table->boolean('show_timer')->default(true)->after('decline_cta');
            $table->unsignedSmallInteger('timer_minutes')->nullable()->after('show_timer');
        });
    }

    public function down(): void
    {
        Schema::table('upsell_flow_offers', function (Blueprint $table) {
            $table->dropColumn([
                'product_selection_mode',
                'variant_selection_mode',
                'purchase_option',
                'apply_discount_on_top',
                'shipping_fee_mode',
                'show_timer',
                'timer_minutes',
            ]);
        });
    }
};
