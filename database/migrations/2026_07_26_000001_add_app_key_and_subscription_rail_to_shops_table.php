<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One deployment now serves TWO Shopify Partner apps (the public App-Store app +
 * the custom stage-1 app real test stores install through), and a shop chooses
 * which engine bills its recurring subscriptions:
 *
 *   - shopify_app_key: which Partner app installed this shop ('public'|'custom').
 *     Session tokens, token exchange, webhooks and App Bridge resolve this shop's
 *     credentials from it (see ShopifyApps).
 *   - subscription_rail: 'payplus' (we hold the token and charge) or
 *     'shopify_payments' (Shopify vaults the card; our scheduler drives
 *     subscriptionBillingAttemptCreate per cycle). Merchant-set in Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('shopify_app_key', 32)->default('public')->after('shopify_scopes');
            $table->string('subscription_rail', 32)->default('payplus')->after('shopify_app_key');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['shopify_app_key', 'subscription_rail']);
        });
    }
};
