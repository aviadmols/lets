<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vault. One saved PayPlus card token per customer payment method. The token
 * UID is encrypted at rest by the model cast. Tenant-scoped (shop_id NOT NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('shopify_customer_id')->nullable();

            // The vault token + PayPlus customer handle (token UID encrypted by cast).
            $table->text('payplus_card_token_uid')->nullable();
            $table->string('payplus_customer_uid')->nullable();

            // Token fallback chain (matches the reference engine): a plain token
            // reference, plus an APP_KEY-encrypted raw token blob read via the
            // rawToken accessor when neither uid nor reference is present.
            $table->string('payplus_token_reference')->nullable();
            $table->text('encrypted_payplus_token')->nullable();

            // Display-safe card metadata.
            $table->string('card_brand')->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->unsignedSmallInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();

            $table->string('status')->default('active');

            $table->timestamps();

            $table->index(['shop_id', 'customer_id']);
            $table->index(['shop_id', 'shopify_customer_id']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payment_methods');
    }
};
