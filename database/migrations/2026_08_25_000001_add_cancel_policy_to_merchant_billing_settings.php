<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOW a customer cancels, once the merchant allows cancelling at all.
 *
 * `allow_customer_cancel` stays the master switch (off = no button anywhere,
 * unchanged). The MODE refines "on": `self_service` is today's one-click
 * cancel; `contact` keeps the button but turns the click into a card with the
 * merchant's contact details — the subscription ends only through support.
 *
 * The contact fields are merchant-typed and read back through guards, like
 * every merchant-typed value that reaches a shopper's page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            // self_service | contact
            $table->string('customer_cancel_mode', 16)->default('self_service');

            $table->string('cancel_contact_email')->nullable();
            $table->string('cancel_contact_phone', 32)->nullable();
            // The sentence the popup opens with ("צרו קשר ונשמח לעזור…").
            $table->string('cancel_contact_note', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'customer_cancel_mode',
                'cancel_contact_email',
                'cancel_contact_phone',
                'cancel_contact_note',
            ]);
        });
    }
};
