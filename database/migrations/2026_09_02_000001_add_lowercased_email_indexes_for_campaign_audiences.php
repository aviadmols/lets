<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make "find these people by email" an index lookup instead of a table scan.
 *
 * A campaign's NAMED LIST resolves each typed address across all three rails,
 * and it must match case-insensitively — somebody who typed `Dana@…` at checkout
 * is the same person as `dana@…` on the merchant's list. The audience builder
 * therefore compares `lower(customer_email)`, and a function applied to a column
 * makes an ordinary index on that column unusable: Postgres scans the whole
 * table, once per campaign, per rail.
 *
 * A FUNCTIONAL index on `lower(customer_email)` is the matching one. Postgres
 * supports these directly; SQLite (the test suite) supports them too, but only
 * for deterministic functions — `lower()` qualifies. MySQL before 8.0.13 does
 * not, so it is skipped rather than failing a deploy on a database this app does
 * not run on.
 *
 * Written CONCURRENTLY on Postgres would be better on a large live table, but
 * that cannot run inside a transaction and Laravel wraps migrations — these
 * tables are per-shop and small enough for a plain build.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    /** table => the email column to index, alongside shop_id. */
    private const TARGETS = [
        'installment_plans' => 'customer_email',
        'subscription_contracts' => 'customer_email',
        'loyalty_accounts' => 'customer_email',
    ];

    private const PREFIX = 'idx_lower_email_';

    public function up(): void
    {
        if (! $this->supportsFunctionalIndexes()) {
            return;
        }

        foreach (self::TARGETS as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (shop_id, lower(%s))',
                self::PREFIX.$table,
                $table,
                $column,
            ));
        }
    }

    public function down(): void
    {
        if (! $this->supportsFunctionalIndexes()) {
            return;
        }

        foreach (array_keys(self::TARGETS) as $table) {
            DB::statement('DROP INDEX IF EXISTS '.self::PREFIX.$table);
        }
    }

    private function supportsFunctionalIndexes(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
