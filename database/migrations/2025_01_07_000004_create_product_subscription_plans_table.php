<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-product/variant subscription plan TEMPLATE — the merchant's reusable
 * config (Recharge "subscription options"), NOT a per-customer instance (that's
 * installment_plans). A customer InstallmentPlan inherits its cadence/discount
 * from the matching template at order.paid time (see ProductPlanTemplateResolver).
 *
 * product_variant_id NULL = the template applies to ALL variants of the product;
 * a non-null variant id makes it variant-specific (and wins over the product-wide
 * one for that variant). Tenant-scoped (shop_id NOT NULL). status is a guarded
 * state machine (PlanTemplateStatus: draft <-> active).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // NULL = applies to all variants; non-null = variant-specific override.
            $table->foreignId('product_variant_id')->nullable()
                ->constrained('product_variants')->nullOnDelete();

            // one_time | subscription (NO per-product installments in v1).
            $table->string('plan_type')->default('subscription');
            // installments | recurring — reuse PlanKind. Templates are recurring.
            $table->string('plan_kind')->default('recurring');

            $table->string('plan_name')->nullable();

            // Recurring cadence (null for one_time). BillingFrequency enum value.
            $table->string('billing_frequency')->nullable();
            $table->unsignedInteger('interval_count')->default(1);

            // none | percent | fixed — discount applied to the variant base price.
            $table->string('discount_type')->default('none');
            $table->decimal('discount_value', 12, 2)->default(0);

            // null = charge "on signup"; else the day-of-month to bill.
            $table->unsignedTinyInteger('charge_day_of_month')->nullable();
            // null = open-ended; else stop after N charges.
            $table->unsignedInteger('expire_after_charges')->nullable();

            // Where this template is offered: storefront_widget/customer_portal/
            // merchant_portal/api (a JSON list of channel keys).
            $table->json('channels')->nullable();

            // active | draft — guarded by PlanTemplateStatus.
            $table->string('status')->default('draft');
            $table->integer('position')->default(0);

            $table->timestamps();

            // List ordering inside a product detail screen.
            $table->index(['shop_id', 'product_id', 'position']);
            // Resolver/UI split by purchase type (one_time vs subscription).
            $table->index(['shop_id', 'product_id', 'plan_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_subscription_plans');
    }
};
