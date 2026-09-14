<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two decisions a subscription merchant has to be able to make for themselves.
 *
 * `recurring_creates_order` — does a recurring cycle materialise an order in the
 * store? For a shop selling a box, yes: the order is the picking slip. For a shop
 * selling a ₪39 membership, every cycle produces an order nobody will ever pick,
 * pack or ship, and twelve months of them buries the real orders in the store's
 * admin. TRUE by default, because that is what every existing shop does today and
 * an upgrade must change nothing.
 *
 * `recurring_charge_description` — the line PayPlus prints on the document it
 * issues for the charge. A terminal set to auto-issue a document per transaction
 * takes the line from the `items` we send, and FALLS BACK TO `more_info` when we
 * send none — which in this app is the idempotency key. That is how a real
 * customer once received a חשבונית מס קבלה whose product name read
 * "payplus_installment_plan_41_payment_2". The merchant writes the sentence
 * instead; NULL means the built-in default, never nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            // Default TRUE: the behaviour every shop has today.
            $table->boolean('recurring_creates_order')->default(true)->after('lock_fulfillment_until_paid');

            // 120 chars because it is a document LINE, not a description field —
            // PayPlus prints it on paperwork a customer reads.
            $table->string('recurring_charge_description', 120)->nullable()->after('recurring_creates_order');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_billing_settings', function (Blueprint $table): void {
            $table->dropColumn(['recurring_creates_order', 'recurring_charge_description']);
        });
    }
};
