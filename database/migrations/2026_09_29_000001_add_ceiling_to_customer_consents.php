<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT a consent covers (App\Domain\Billing\ConsentCeiling).
 *
 * The consented per-cycle amount (installments: the plan total) and cadence, where
 * the ceiling came from (customer / migrated / baseline / merchant_override), and —
 * for a merchant override — who approved it and why. Nullable and additive: a row
 * written before this takes its plan's terms as a baseline the first time the plan
 * is charged, so no existing subscription stops billing on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_consents', function (Blueprint $table): void {
            $table->decimal('consented_amount', 12, 2)->nullable();
            $table->string('consented_frequency', 16)->nullable();
            $table->unsignedSmallInteger('consented_interval')->nullable();
            $table->string('ceiling_source', 32)->nullable();
            $table->string('approved_by')->nullable();
            $table->text('approval_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_consents', function (Blueprint $table): void {
            $table->dropColumn([
                'consented_amount', 'consented_frequency', 'consented_interval',
                'ceiling_source', 'approved_by', 'approval_reason',
            ]);
        });
    }
};
