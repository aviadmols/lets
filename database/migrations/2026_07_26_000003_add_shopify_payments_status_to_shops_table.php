<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the store actually sells through SHOPIFY PAYMENTS, as reported by
 * Shopify itself. Three states, never two: `unknown` is a real answer — the
 * detection needs an API the app may not be granted, and a store must never be
 * re-tagged (or have its PayPlus settings hidden) on a guess.
 *
 * A store detected as `active` that has no PayPlus credentials is tagged onto
 * the Shopify-Payments rail automatically; the merchant can always override the
 * engine in Settings → Billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('shopify_payments_status', 16)->default('unknown')->after('subscription_rail');
            $table->timestamp('shopify_payments_checked_at')->nullable()->after('shopify_payments_status');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['shopify_payments_status', 'shopify_payments_checked_at']);
        });
    }
};
