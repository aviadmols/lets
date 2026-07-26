<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-PLAN billing-rail override + the Shopify selling-plan link.
 *
 * The shop-wide engine choice lives on shops.subscription_rail; this lets ONE
 * product's subscription template opt into the other engine (null = follow the
 * shop). A template on the Shopify-Payments rail must exist AT SHOPIFY as a
 * selling plan, because that is what makes the product subscribable at checkout
 * and what produces a contract our app owns — the gids below are that link, and
 * their presence is the difference between "configured" and "actually live".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_subscription_plans', function (Blueprint $table): void {
            $table->string('billing_rail', 32)->nullable()->after('plan_type');
            $table->string('shopify_selling_plan_group_gid')->nullable()->after('billing_rail');
            $table->string('shopify_selling_plan_gid')->nullable()->after('shopify_selling_plan_group_gid');
            $table->timestamp('shopify_synced_at')->nullable()->after('shopify_selling_plan_gid');
        });
    }

    public function down(): void
    {
        Schema::table('product_subscription_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'billing_rail',
                'shopify_selling_plan_group_gid',
                'shopify_selling_plan_gid',
                'shopify_synced_at',
            ]);
        });
    }
};
