<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;

/**
 * THE RULES THAT COST US SOMETHING TO LEARN, pinned as tests rather than prose.
 *
 * Each of these was a real defect before it was a rule, and each is the kind
 * that a normal review reads straight past because the code looks reasonable.
 * A convention nobody can accidentally break is worth more than one everybody
 * agrees with.
 */
final class MoneyPathConventionsTest extends TestCase
{
    // === CONSTANTS ===
    private const APP = __DIR__.'/../../../app';

    /**
     * No network call may sit inside a database transaction.
     *
     * A gateway or store round trip holds the connection and every lock the
     * transaction has taken for the length of somebody else's timeout, and a
     * failure mid-flight unwinds the rows written to survive exactly that. The
     * charge pipeline and the upsell both learned this the hard way and are now
     * built in phases around it.
     */
    public function test_no_http_or_gateway_call_inside_a_database_transaction(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->phpFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach ($this->transactionBodies($source) as $body) {
                $scanned++;

                foreach (['Http::', '->chargeWithReference(', '->graphql(', '->refund('] as $needle) {
                    if (str_contains($body, $needle)) {
                        $offenders[] = $this->relative($file).' — '.$needle;
                    }
                }
            }
        }

        // Guard the guard. A scanner that quietly stopped matching would report a
        // clean codebase forever, which is the one failure mode a test like this
        // must not have.
        $this->assertGreaterThan(
            10,
            $scanned,
            'The transaction scanner found almost nothing to look at — it has stopped working, '
            .'and its "no offenders" verdict means nothing.',
        );

        $this->assertSame(
            [],
            $offenders,
            'A network call is inside DB::transaction. It holds a connection and its locks for the '
            ."length of a remote timeout, and a death mid-call rolls back the record of the attempt.\n"
            .'Split it into phases the way ChargeOrchestrator::charge() and UpsellChargeService do: '
            ."commit the intent, call out holding nothing, commit the outcome.\n  ".implode("\n  ", $offenders),
        );
    }

    /**
     * Every scheduled command's overlap lock must state its own expiry.
     *
     * withoutOverlapping() defaults to TWENTY-FOUR HOURS. A worker killed rather
     * than finished — a redeploy, an OOM — never releases the lock, so on the
     * charge dispatcher that default means every shop stops billing for a day,
     * silently, because a container restarted at the wrong moment.
     */
    public function test_every_schedule_sets_an_explicit_overlap_expiry(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/->withoutOverlapping\(\s*\)/', $source) === 1) {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'withoutOverlapping() with no argument locks for 24 hours. A killed run must cost one '
            ."skipped tick, not a day of silence — pass an expiry in minutes.\n  ".implode("\n  ", $offenders),
        );
    }

    /**
     * A queued job must be told its tenant, never left to infer one.
     *
     * A worker is long-lived and serves every shop in turn; a job that reads the
     * shop from ambient state gets whichever one the previous job left behind.
     */
    public function test_every_queued_job_carries_a_shop_id(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'implements ShouldQueue') && ! str_contains($source, 'ShouldQueue,')) {
                continue;
            }

            // The platform-wide jobs are the deliberate exceptions: they serve no
            // single tenant, and they say so by name.
            if (str_contains($source, 'shopId') || str_contains($source, 'shop_id')) {
                continue;
            }

            $offenders[] = $this->relative($file);
        }

        $this->assertSame(
            [],
            $offenders,
            'A queued job does not carry a shop_id. On a shared worker it will act on whichever '
            ."tenant the last job happened to bind.\n  ".implode("\n  ", $offenders),
        );
    }

    // === Helpers ===

    /**
     * The source of every `DB::transaction(` closure in a file, brace-matched.
     *
     * Crude on purpose: a real parser would be more precise, and would also be a
     * dependency and a thing to maintain. This over-reports rather than under-
     * reports, which is the correct direction for a guard.
     *
     * @return list<string>
     */
    private function transactionBodies(string $source): array
    {
        $bodies = [];
        $offset = 0;

        while (($start = strpos($source, 'DB::transaction(', $offset)) !== false) {
            $depth = 0;
            $end = null;

            for ($i = $start + strlen('DB::transaction('), $n = strlen($source); $i < $n; $i++) {
                $char = $source[$i];

                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                    $depth--;
                }
            }

            if ($end === null) {
                break;
            }

            $bodies[] = substr($source, $start, $end - $start);
            $offset = $end;
        }

        return $bodies;
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::APP, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strpos($path, 'app') ?: 0));
    }
}
