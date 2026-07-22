<?php

namespace App\Support;

use Illuminate\Database\ConfigurationUrlParser;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Refuses a table-dropping artisan command unless the database it would actually
 * hit is demonstrably LOCAL.
 *
 * WHY THIS EXISTS (a real incident, 2026-07-22): `migrate:fresh --database=sqlite`
 * dropped every table in a live Railway Postgres. The sqlite connection carried
 * `'url' => env('DB_URL')`, and Laravel's ConfigurationUrlParser lets a url
 * OVERRIDE the driver — so "sqlite" resolved to the production Postgres server.
 * The config key is gone now; this is the second line of defence, and it covers
 * the case the config fix does not: a plain `migrate:fresh` against the DEFAULT
 * connection, which in a deployed .env is production.
 *
 * Laravel's own ConfirmableTrait does not help here: it prompts only when
 * app()->environment('production'), and a developer's laptop is `local` while its
 * .env points at a production database. The environment is not the risk — the
 * CONNECTION is. So we judge the connection.
 *
 * The check resolves config THE SAME WAY the connection factory does (URL parsing
 * included), so a url that rewrites the driver cannot slip past it.
 *
 * Escape hatch: set ALLOW_DESTRUCTIVE_DB=true in .env. Deliberately an env var and
 * not a CLI flag — wiping a remote database should require editing a file and
 * meaning it, not appending --force to a command you already typed.
 */
final class DestructiveCommandGuard
{
    // === CONSTANTS ===

    /** Commands that DROP tables. Anything here is refused against a remote DB. */
    public const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    /** Hosts that count as "this machine". Empty host = a socket or a file DB. */
    public const LOCAL_HOSTS = [
        '',
        'localhost',
        '127.0.0.1',
        '::1',
        'host.docker.internal',
    ];

    /** The env flag that consciously disables this guard. */
    public const OVERRIDE_ENV = 'ALLOW_DESTRUCTIVE_DB';

    /**
     * Throw when $command would drop tables on a non-local database.
     *
     * @throws RuntimeException
     */
    public static function assertSafe(?string $command, InputInterface $input): void
    {
        if ($command === null || ! in_array($command, self::DESTRUCTIVE_COMMANDS, true)) {
            return;
        }

        if (filter_var(env(self::OVERRIDE_ENV, false), FILTER_VALIDATE_BOOLEAN)) {
            return; // the operator deliberately opted in
        }

        $name = self::targetConnection($input);
        $resolved = self::resolve($name);

        if (self::isLocal($resolved)) {
            return;
        }

        throw new RuntimeException(self::message($command, $name, $resolved));
    }

    /**
     * The connection the command will use: its --database option, else the app
     * default. Read from RAW input — at CommandStarting the input is not yet bound
     * to the command definition, so getOption() would throw.
     */
    private static function targetConnection(InputInterface $input): string
    {
        $option = $input->getParameterOption('--database', null);

        return is_string($option) && $option !== ''
            ? $option
            : (string) config('database.default');
    }

    /**
     * The connection config AFTER url parsing — i.e. what the connection factory
     * will really build. Parsing here is the whole point: a `url` may rewrite the
     * driver and host, and a guard that read the raw array would be reassured by
     * exactly the value that caused the incident.
     *
     * @return array<string, mixed>
     */
    private static function resolve(string $name): array
    {
        $config = (array) config('database.connections.'.$name, []);

        if ($config === []) {
            // An unknown connection name cannot be proven safe.
            return ['driver' => 'unknown', 'host' => 'unknown'];
        }

        return (new ConfigurationUrlParser)->parseConfiguration($config);
    }

    /**
     * Is this connection unambiguously local?
     *
     * sqlite counts only when it is in-memory or a real filesystem path — a sqlite
     * config that somehow still carries a host is treated as remote.
     *
     * @param  array<string, mixed>  $config
     */
    private static function isLocal(array $config): bool
    {
        $driver = (string) ($config['driver'] ?? '');
        $host = strtolower(trim((string) ($config['host'] ?? '')));

        if ($driver === 'sqlite') {
            return $host === '';
        }

        if ($driver === 'unknown') {
            return false;
        }

        return in_array($host, self::LOCAL_HOSTS, true);
    }

    /** @param array<string, mixed> $config */
    private static function message(string $command, string $name, array $config): string
    {
        $driver = (string) ($config['driver'] ?? 'unknown');
        $host = (string) ($config['host'] ?? '');
        $database = (string) ($config['database'] ?? '');

        return implode(PHP_EOL, [
            '',
            "REFUSED: `{$command}` would DROP EVERY TABLE on a non-local database.",
            '',
            "  connection asked for : {$name}",
            "  actually resolves to : {$driver} @ ".($host !== '' ? $host : '(no host)'),
            "  database             : {$database}",
            '',
            '  A connection NAME does not guarantee a target: a `url` in the config can',
            '  override the driver and host. The values above are what the connection',
            '  factory would really build.',
            '',
            '  If you truly mean to wipe this database, set '.self::OVERRIDE_ENV.'=true in',
            '  your .env, run the command, then remove it again.',
            '',
        ]);
    }
}
