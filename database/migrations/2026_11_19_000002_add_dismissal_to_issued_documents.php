<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A FAILED document a person decided is not needed: the invoice for a test
 * order, a sale that was voided another way. The row stays, with its failure
 * fields as they were, and says who dismissed it, when and why. Status becomes
 * `dismissed`, which is not retryable, so no automatic path ever issues it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->timestamp('dismissed_at')->nullable()->after('issued_at');
            $table->unsignedBigInteger('dismissed_by')->nullable()->after('dismissed_at');
            $table->string('dismiss_reason', 500)->nullable()->after('dismissed_by');
        });
    }

    public function down(): void
    {
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->dropColumn(['dismissed_at', 'dismissed_by', 'dismiss_reason']);
        });
    }
};
