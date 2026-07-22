<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a PLAIN STORE ORDER document be re-issued faithfully.
 *
 * A plan-backed document rebuilds itself from the ledger row and the plan. A
 * platform order has neither: the storefront reports it once, we build the
 * document, and the report is gone. A retry therefore had to invent the missing
 * fields — and inventing `payment_gateway` is not a cosmetic loss, because the
 * provider reads "no gateway" as "LETS card clearing" and would declare a bank
 * transfer or a cash-on-delivery order as a CREDIT CARD payment on a tax
 * document. The customer name was empty for the same reason.
 *
 * So the neutral report is kept on the row. It holds customer identity, and is
 * therefore scrubbed by BOTH redaction jobs exactly like raw_response_masked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_documents', function (Blueprint $table) {
            $table->json('source_payload')->nullable()->after('external_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('issued_documents', function (Blueprint $table) {
            $table->dropColumn('source_payload');
        });
    }
};
