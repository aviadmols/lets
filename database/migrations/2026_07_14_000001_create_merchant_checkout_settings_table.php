<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shop PayPlus PAYMENT-PAGE options (W15). One row per shop (shop_id unique, so
 * MerchantCheckoutSettings::current()'s firstOrCreate can never race two rows), cascading
 * with the shop. Non-secret → plain columns, not the encrypted credentials bag.
 *
 * Every column maps 1:1 to a DOCUMENTED PayPlus generateLink field (see PayPlusPageOptions).
 * Defaults reproduce today's hard-coded page exactly, so existing shops see no behaviour
 * change until the merchant opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_checkout_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained('shops')->cascadeOnDelete();

            // Page presentation.
            $table->string('language_code', 8)->nullable();       // PayPlus: language_code
            $table->string('charge_default', 32)->nullable();     // PayPlus: charge_default
            $table->json('allowed_charge_methods')->nullable();   // PayPlus: allowed_charge_methods
            $table->boolean('hide_other_charge_methods')->default(false);

            // Installments.
            $table->unsignedSmallInteger('max_payments')->default(1);   // PayPlus: payments
            $table->unsignedSmallInteger('payments_selected')->nullable(); // PayPlus: payments_selected
            $table->boolean('payments_credit')->default(false);          // PayPlus: payments_credit

            // Field visibility.
            $table->boolean('add_user_information')->default(true);   // PayPlus: add_user_information
            $table->boolean('hide_identification_id')->default(false);
            $table->boolean('hide_payments_field')->default(false);

            // Receipts.
            $table->boolean('send_email_approval')->default(false);   // PayPlus: sendEmailApproval
            $table->boolean('send_email_failure')->default(false);    // PayPlus: sendEmailFailure

            // Misc.
            $table->unsignedSmallInteger('expiry_minutes')->nullable(); // PayPlus: expiry_datetime
            $table->boolean('secure3d')->default(false);                // PayPlus: secure3d

            // THE upsell enabler: ask PayPlus to return a reusable token for the card.
            // Without this no token is ever vaulted and one-click upsell cannot charge.
            $table->boolean('create_token')->default(false);            // PayPlus: create_token

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_checkout_settings');
    }
};
