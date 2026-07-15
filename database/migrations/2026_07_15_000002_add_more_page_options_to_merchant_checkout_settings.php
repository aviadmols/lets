<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W16 Part B — the remaining DOCUMENTED PayPlus generateLink page options, added to the per-shop
 * merchant_checkout_settings row. All default to null/false, so a shop that never opens the form
 * sends exactly what it sends today. Applied (allow-listed) by PayPlusPageOptions to every PayPlus
 * page. NOT secrets → plain columns, not the encrypted credentials bag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_checkout_settings', function (Blueprint $table): void {
            // First-installment amount (only meaningful when installments are offered).
            $table->decimal('payments_first_amount', 12, 2)->nullable();
            // Minimum order value for a (non-voucher) card charge.
            $table->decimal('non_voucher_minimum_amount', 12, 2)->nullable();
            // Card brands the page accepts (subset of MerchantCheckoutSettings::ALLOWED_CARDS).
            $table->json('allowed_cards')->nullable();
            // SMS receipts to the customer on success / failure.
            $table->boolean('send_customer_success_sms')->default(false);
            $table->boolean('send_customer_failure_sms')->default(false);
            // Extra explanatory text shown on the page.
            $table->string('more_info_text', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_checkout_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'payments_first_amount',
                'non_voucher_minimum_amount',
                'allowed_cards',
                'send_customer_success_sms',
                'send_customer_failure_sms',
                'more_info_text',
            ]);
        });
    }
};
