<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns the refund path has always needed and never had.
 *
 * `refunded_amount` — because a PARTIAL refund used to flip the whole row to
 * `refunded` with no record of how much went back. A ₪50 credit against a ₪200
 * charge read as ₪200 refunded in every report, and the second partial refund
 * was refused as "already refunded" even though the credit-note key is built
 * to allow exactly that.
 *
 * `payment_id` — because the code that transitions the refunded installment
 * SLOT reads `$row->payment_id`, a column that does not exist. It was always
 * null, so the slot stayed `succeeded`, `total_charged` was never reduced, and
 * a refunded plan still reported itself fully paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table): void {
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('amount');
            $table->unsignedBigInteger('payment_id')->nullable()->after('plan_id');

            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table): void {
            $table->dropIndex(['payment_id']);
            $table->dropColumn(['refunded_amount', 'payment_id']);
        });
    }
};
