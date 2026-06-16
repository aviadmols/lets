<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SAFETY NET. The test suite must NEVER run against a real database — a single
 * RefreshDatabase test would migrate:fresh and wipe it. `.env` may legitimately
 * point DB_HOST/DB_URL at a live (e.g. Railway) Postgres for local runs, so this
 * guard asserts the *testing* connection is sqlite :memory: (forced by phpunit.xml).
 * It uses NO RefreshDatabase and runs NO migration — it only inspects config, so
 * it is itself harmless even if the isolation were broken.
 */
final class DbIsolationGuardTest extends TestCase
{
    public function test_tests_use_sqlite_memory_not_a_real_database(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName(),
            'Tests must use sqlite — a real driver here means RefreshDatabase could wipe a live DB.');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName(),
            'Tests must use the in-memory sqlite database, never a file or remote DB.');
        $this->assertNull(env('DB_URL'),
            'DB_URL must be null in tests (phpunit.xml), else the sqlite connection parses it into a real DB.');
    }
}
