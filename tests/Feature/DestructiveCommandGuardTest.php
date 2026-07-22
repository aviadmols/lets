<?php

namespace Tests\Feature;

use App\Support\DestructiveCommandGuard;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StringInput;
use Tests\TestCase;

/**
 * Regression cover for a real incident (2026-07-22): `migrate:fresh
 * --database=sqlite` dropped every table in a live Railway Postgres, because the
 * sqlite connection carried `'url' => env('DB_URL')` and Laravel's URL parser
 * lets a url override the driver.
 *
 * Two independent fixes are asserted here: the sqlite connection no longer has a
 * `url` key at all, and the guard refuses table-dropping commands against any
 * database it cannot prove is local.
 *
 * This test runs NO migration and touches NO database — it only inspects config
 * and calls the guard, so it is itself harmless.
 */
final class DestructiveCommandGuardTest extends TestCase
{
    // === CONSTANTS ===
    private const REMOTE_URL = 'postgresql://user:pw@prod-db.proxy.rlwy.net:15370/railway';

    protected function tearDown(): void
    {
        putenv(DestructiveCommandGuard::OVERRIDE_ENV);
        parent::tearDown();
    }

    public function test_the_sqlite_connection_cannot_be_rewritten_by_a_url(): void
    {
        // The root cause. A sqlite database is a file path; it has no URL to parse,
        // and a `url` key here is what let "sqlite" mean "production Postgres".
        $this->assertArrayNotHasKey(
            'url',
            (array) config('database.connections.sqlite'),
            'The sqlite connection must not carry a url — it can override the driver.',
        );
    }

    public function test_it_refuses_a_fresh_migrate_against_a_remote_database(): void
    {
        config()->set('database.connections.remote', [
            'driver' => 'pgsql',
            'host' => 'prod-db.proxy.rlwy.net',
            'port' => 15370,
            'database' => 'railway',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/would DROP EVERY TABLE/');

        DestructiveCommandGuard::assertSafe('migrate:fresh', new StringInput('--database=remote'));
    }

    public function test_it_sees_through_a_url_that_rewrites_the_driver(): void
    {
        // THE INCIDENT, exactly. A connection NAMED sqlite whose url points at a
        // remote Postgres. A guard that read the raw `driver` key would be reassured
        // by the very value that caused the outage, so it must parse the url first.
        config()->set('database.connections.trap', [
            'driver' => 'sqlite',
            'url' => self::REMOTE_URL,
            'database' => ':memory:',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pgsql @ prod-db\.proxy\.rlwy\.net/');

        DestructiveCommandGuard::assertSafe('migrate:fresh', new StringInput('--database=trap'));
    }

    public function test_it_refuses_when_no_database_option_is_given_and_the_default_is_remote(): void
    {
        // The route the config fix alone does NOT close: a bare `migrate:fresh`
        // against a deployed .env, where the DEFAULT connection is production.
        config()->set('database.default', 'remote');
        config()->set('database.connections.remote', [
            'driver' => 'pgsql',
            'host' => 'db.internal.railway.app',
            'database' => 'railway',
        ]);

        $this->expectException(RuntimeException::class);

        DestructiveCommandGuard::assertSafe('migrate:fresh', new StringInput(''));
    }

    public function test_it_allows_in_memory_sqlite(): void
    {
        // The test suite's own connection. RefreshDatabase runs migrate:fresh on
        // every test, so the guard must never stand in its way.
        DestructiveCommandGuard::assertSafe('migrate:fresh', new StringInput('--database=sqlite'));

        $this->assertSame('sqlite', config('database.connections.sqlite.driver'));
    }

    public function test_it_allows_a_localhost_database(): void
    {
        config()->set('database.connections.local_pg', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'database' => 'app_dev',
        ]);

        DestructiveCommandGuard::assertSafe('migrate:fresh', new StringInput('--database=local_pg'));

        $this->addToAssertionCount(1); // no exception = allowed
    }

    public function test_it_ignores_non_destructive_commands(): void
    {
        config()->set('database.default', 'remote');
        config()->set('database.connections.remote', [
            'driver' => 'pgsql',
            'host' => 'prod-db.proxy.rlwy.net',
            'database' => 'railway',
        ]);

        // `migrate` ADDS tables; it does not drop them. Deploys must keep working.
        DestructiveCommandGuard::assertSafe('migrate', new StringInput(''));
        DestructiveCommandGuard::assertSafe('queue:work', new ArrayInput([]));

        $this->addToAssertionCount(1);
    }

    public function test_an_unknown_connection_is_treated_as_remote(): void
    {
        // Fail closed: a name we cannot resolve cannot be proven safe.
        $this->expectException(RuntimeException::class);

        DestructiveCommandGuard::assertSafe('db:wipe', new StringInput('--database=who_knows'));
    }

    public function test_the_env_override_lets_a_deliberate_wipe_through(): void
    {
        config()->set('database.connections.remote', [
            'driver' => 'pgsql',
            'host' => 'prod-db.proxy.rlwy.net',
            'database' => 'railway',
        ]);

        putenv(DestructiveCommandGuard::OVERRIDE_ENV.'=true');

        DestructiveCommandGuard::assertSafe('migrate:fresh', new StringInput('--database=remote'));

        $this->addToAssertionCount(1);
    }

    /**
     * END-TO-END through the real console pipeline, not just the guard in isolation.
     *
     * This is the assertion that actually matters: a unit test proving assertSafe()
     * throws is worthless if the listener is never wired to CommandStarting. The
     * console kernel only re-routes Symfony's command events when it is NOT running
     * unit tests, so WithConsoleEvents turns that back on for this test.
     *
     * No real database is touched — the guard refuses before the command executes.
     */
    public function test_the_guard_is_actually_wired_into_the_console_pipeline(): void
    {
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->rerouteSymfonyCommandEvents();

        config()->set('database.connections.fake_remote', [
            'driver' => 'pgsql',
            'host' => 'prod-db.proxy.rlwy.net',
            'port' => 15370,
            'database' => 'railway',
        ]);

        // Explicit try/catch rather than expectException: $this->artisan() returns a
        // PendingCommand that may only execute on destruct, and a test that passes
        // because nothing ran would be worse than no test at all.
        $thrown = null;

        try {
            Artisan::call('migrate:fresh', ['--database' => 'fake_remote']);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'migrate:fresh reached a remote database — the guard is not wired in.');
        $this->assertStringContainsString('REFUSED', $thrown->getMessage());
        $this->assertStringContainsString('prod-db.proxy.rlwy.net', $thrown->getMessage());
    }

    public function test_every_table_dropping_command_is_covered(): void
    {
        config()->set('database.connections.remote', [
            'driver' => 'pgsql',
            'host' => 'prod-db.proxy.rlwy.net',
            'database' => 'railway',
        ]);

        foreach (DestructiveCommandGuard::DESTRUCTIVE_COMMANDS as $command) {
            try {
                DestructiveCommandGuard::assertSafe($command, new StringInput('--database=remote'));
                $this->fail("`{$command}` drops tables but was allowed against a remote database.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('REFUSED', $e->getMessage());
            }
        }
    }
}
