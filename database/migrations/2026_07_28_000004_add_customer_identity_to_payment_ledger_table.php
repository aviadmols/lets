<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger could only ever NAME a customer through a linked InstallmentPlan —
 * the plan is where checkout captured the name. A plain store checkout has no
 * plan, so its row fell back to the raw external id and the Payments screen
 * showed a bare email (or nothing) where the merchant expects a person.
 *
 * These two columns let a row carry the identity of the money it records,
 * whatever produced it. Denormalised on purpose: a ledger row is a historical
 * record, and the name on it must stay what it was at the time of the charge
 * even if the customer later changes theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table): void {
            $table->string('customer_name')->nullable()->after('shopify_customer_id');
            $table->string('customer_email')->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table): void {
            $table->dropColumn(['customer_name', 'customer_email']);
        });
    }
};
