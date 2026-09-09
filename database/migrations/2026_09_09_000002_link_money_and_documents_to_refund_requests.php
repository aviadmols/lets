<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point the two money-truth tables back at the request that moved them.
 *
 * `payment_ledger.refund_request_id` — which click reversed this charge.
 * `issued_documents.refund_request_id` — which click produced this credit note.
 *
 * The second one is the only direction that can work: a document is issued from
 * a QUEUED job, so at the moment the request finishes it does not yet know the
 * document's id. Naming the request on the document instead lets the drawer show
 * "credit note 1042 ✓" as soon as the job lands, and lets a merchant's
 * accountant walk from a credit note back to the decision that made it.
 *
 * Both are nullable and unconstrained-by-design at the database level (a request
 * may be pruned long after its ledger row is not) — the column is an audit
 * pointer, never a load-bearing relation.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const TABLES = ['payment_ledger', 'issued_documents'];

    private const COLUMN = 'refund_request_id';

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, self::COLUMN)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger(self::COLUMN)->nullable();
                $blueprint->index(self::COLUMN);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, self::COLUMN)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex([self::COLUMN]);
                $blueprint->dropColumn(self::COLUMN);
            });
        }
    }
};
