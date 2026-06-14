<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit customer consent for future charges against a saved PayPlus token.
 * Every plan/charge that can hit a saved token must have a consent row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('shopify_customer_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();

            // installments|recurring|upsell
            $table->string('consent_context');
            $table->string('accepted_terms_version')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->string('customer_email')->nullable();
            $table->string('customer_ip')->nullable();
            $table->text('user_agent')->nullable();

            $table->text('billing_amount_description')->nullable();
            $table->text('billing_frequency_description')->nullable();
            $table->text('cancellation_policy_snapshot')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'customer_id']);
            $table->index(['shop_id', 'plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_consents');
    }
};
