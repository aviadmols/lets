<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The contract's Shopify payment method, mirrored for DISPLAY + the card-update
 * email action. Shopify vaults the card; we hold only the reference (gid) and
 * the presentation fields (brand / last four / expiry) its API exposes. All
 * nullable: reading the instrument needs the customer-payment-methods scope +
 * the protected-data approval, and a mirror that predates them simply shows
 * "not readable yet".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_contracts', function (Blueprint $table): void {
            $table->string('payment_method_gid')->nullable()->after('customer_email');
            $table->string('card_brand', 32)->nullable()->after('payment_method_gid');
            $table->string('card_last_four', 4)->nullable()->after('card_brand');
            $table->string('card_exp', 8)->nullable()->after('card_last_four');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_contracts', function (Blueprint $table): void {
            $table->dropColumn(['payment_method_gid', 'card_brand', 'card_last_four', 'card_exp']);
        });
    }
};
