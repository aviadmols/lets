<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Subscribers get N% off these categories" — a WooCommerce store's perk for
 * customers whose subscription is paid and running.
 *
 * OFF by default with no percent and no categories, so no store's prices move
 * until the merchant turns it on. The discount itself is applied by the plugin
 * in the cart; LETS only stores the rule and answers "is this email a subscriber".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->boolean('subscriber_discount_enabled')->default(false);
            $table->decimal('subscriber_discount_percent', 5, 2)->nullable();
            $table->json('subscriber_discount_category_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->dropColumn(['subscriber_discount_enabled', 'subscriber_discount_percent', 'subscriber_discount_category_ids']);
        });
    }
};
