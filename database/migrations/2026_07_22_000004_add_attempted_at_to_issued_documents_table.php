<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the one remaining double-issue hole: a worker killed BETWEEN the provider
 * accepting a document and our row being updated.
 *
 * Without this column the row stays `pending`, a redelivered job treats it as a
 * fresh attempt, and POSTs a second document — and Green Invoice has no idempotency
 * key of its own, so that is a second REAL tax document, double-declaring VAT.
 *
 * `attempted_at` is stamped immediately BEFORE the HTTP call. A pending row that
 * carries one has an UNKNOWN outcome, so DocumentIssuer never re-posts it: it moves
 * to `unresolved` for a human (or a provider-side search) to reconcile. Losing a
 * document a merchant can re-issue is recoverable; declaring income twice is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_documents', function (Blueprint $table) {
            $table->timestamp('attempted_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('issued_documents', function (Blueprint $table) {
            $table->dropColumn('attempted_at');
        });
    }
};
