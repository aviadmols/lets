<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Friend brings a friend": a member shares a link, whoever buys through it
 * gets a discount, and the member earns points on every such purchase.
 *
 * The attribution mechanism is deliberately NOT a cookie. Each member's referral
 * code is minted as a REAL discount code in the merchant's store, and the shared
 * link is the platform's own apply-discount URL. The buyer's order therefore
 * carries the code natively — Shopify puts it in `discount_codes[]`, WooCommerce
 * in `coupon_lines[]` — which we already capture at checkout. That makes the
 * attribution as reliable as the discount itself: no cookie to expire, nothing
 * lost to a different browser, and the buyer's own receipt is the evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_loyalty_settings', function (Blueprint $table): void {
            $table->boolean('referral_enabled')->default(false)->after('social_actions');
            // What the FRIEND gets for arriving through the link.
            $table->string('referral_discount_type', 16)->default('percent')->after('referral_enabled');
            $table->decimal('referral_discount_value', 10, 2)->default(0)->after('referral_discount_type');
            // What the MEMBER earns per purchase made through their link.
            $table->unsignedInteger('referral_points_per_order')->default(0)->after('referral_discount_value');
            $table->unsignedInteger('referral_points_per_currency')->default(0)->after('referral_points_per_order');
        });

        Schema::table('loyalty_accounts', function (Blueprint $table): void {
            // The member's own share code — also the discount code in the store.
            $table->string('referral_code', 32)->nullable()->after('customer_ref');
            $table->timestamp('referral_synced_at')->nullable()->after('referral_code');

            $table->unique(['shop_id', 'referral_code']);
        });

        Schema::create('loyalty_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('referrer_account_id')->constrained('loyalty_accounts')->cascadeOnDelete();

            // Who bought, and what it was worth. buyer_ref is the platform's
            // customer reference when the order had one (a guest leaves it null,
            // and the referral still counts — the order is the proof).
            $table->string('buyer_ref')->nullable();
            $table->string('buyer_email')->nullable();
            $table->string('external_order_id')->nullable();
            $table->decimal('order_amount', 12, 2)->default(0);
            $table->integer('points_awarded')->default(0);

            $table->timestamps();

            // One referral per order — the wall against a redelivered webhook
            // paying the referrer twice for one purchase.
            $table->unique(['shop_id', 'external_order_id']);
            $table->index(['shop_id', 'referrer_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_referrals');

        Schema::table('loyalty_accounts', function (Blueprint $table): void {
            $table->dropUnique(['shop_id', 'referral_code']);
            $table->dropColumn(['referral_code', 'referral_synced_at']);
        });

        Schema::table('merchant_loyalty_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'referral_enabled',
                'referral_discount_type',
                'referral_discount_value',
                'referral_points_per_order',
                'referral_points_per_currency',
            ]);
        });
    }
};
