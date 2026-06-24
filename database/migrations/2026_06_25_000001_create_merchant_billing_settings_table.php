<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shop billing policy (App\Models\MerchantBillingSettings, plan §4.7). Exactly
 * ONE row per shop. Tenant-scoped (shop_id + BelongsToShop on the model).
 *
 * Governs the merchant's retry policy, installment bounds (the SERVER-SIDE money
 * wall the storefront quote/start path is clamped to), customer self-service
 * (portal pause/cancel), and the policy/terms snapshot written into every consent.
 * UI/preference storage — it does not contain secrets, so nothing is encrypted.
 *
 * A sibling of the mail_settings table; mirrors its one-row-per-shop shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_billing_settings', function (Blueprint $table) {
            $table->id();
            // One row per shop — unique so current() can firstOrCreate safely.
            $table->foreignId('shop_id')->unique()->constrained('shops')->cascadeOnDelete();

            // Retry policy (ChargeOrchestrator backoff + attempt ceiling + grace).
            $table->json('retry_backoff_hours')->nullable();        // [4,24,72] hours per attempt
            $table->unsignedInteger('max_charge_attempts')->default(3);
            $table->unsignedInteger('failed_payment_grace_days')->default(3);

            // Installment bounds (the server-side clamp wall).
            $table->unsignedInteger('min_deposit_percent')->default(10);
            $table->decimal('min_deposit_amount', 12, 2)->nullable();
            $table->unsignedInteger('max_installments')->default(12);
            $table->json('allowed_frequencies')->nullable();        // subset of BillingFrequency
            $table->boolean('lock_fulfillment_until_paid')->default(true);

            // Customer self-service (portal gates).
            $table->boolean('allow_customer_pause')->default(true);
            $table->boolean('allow_customer_cancel')->default(true);

            // Policy / terms (snapshotted into CustomerConsent).
            $table->text('cancellation_policy_text')->nullable();
            $table->string('terms_version')->default('v1');
            $table->string('support_email')->nullable();

            // Default upsell child-order strategy (platform shape).
            $table->string('default_upsell_order_strategy')->default('draft_order_child');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_billing_settings');
    }
};
