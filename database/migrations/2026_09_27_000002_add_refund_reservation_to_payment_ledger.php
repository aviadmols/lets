<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A refund RESERVED on the row before the gateway is called.
 *
 * The over-limit check used to read `refunded_amount` unlocked, call PayPlus,
 * and only then lock the row — so two refunds racing on one charge could both
 * pass "does this fit in what is left?" and both reach PayPlus. RefundService
 * now locks the row, checks, and writes the amount it is about to send here, in
 * one transaction; a second refund meanwhile sees it and is refused. Settled
 * (moved into refunded_amount, or released) after the call.
 *
 * Additive: 0 / null on every existing row means "nothing in flight".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table): void {
            $table->decimal('refunding_amount', 12, 2)->default(0)->after('refunded_amount');
            $table->timestamp('refunding_started_at')->nullable()->after('refunding_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table): void {
            $table->dropColumn(['refunding_amount', 'refunding_started_at']);
        });
    }
};
