<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CSV import's stable key — the `membership_id` from whatever system the
 * merchant is migrating off.
 *
 * A migration file is edited and re-imported, so the importer must be able to say
 * "this row is the plan I created last time" rather than creating a second
 * subscription for the same person every run. public_id (our ULID) answers that
 * for a round-trip export→edit→import, but the FIRST import has no public_id yet:
 * the only identity the file carries is the legacy membership id. Hence a real
 * column with a real unique index — a JSON meta key cannot be uniquely indexed
 * portably, and "unique" is the whole point.
 *
 * Unique per SHOP, not globally: two merchants migrating off the same platform
 * will both have a membership 1001, and they are different subscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->string('import_key')->nullable()->after('public_id');
            $table->unique(['shop_id', 'import_key']);
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table): void {
            $table->dropUnique(['shop_id', 'import_key']);
            $table->dropColumn('import_key');
        });
    }
};
