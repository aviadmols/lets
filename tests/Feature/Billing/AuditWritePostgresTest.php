<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * THE POSTGRES SYMPTOM, end to end.
 *
 * AuditWriteSavepointTest pins the MECHANISM (a savepoint is opened) on every
 * driver. This pins what the mechanism is FOR, and it can only be exercised on
 * the database production actually runs: a statement that errors inside a
 * Postgres transaction aborts the whole transaction, so without the savepoint
 * the money write standing after a failed audit write dies with it — "current
 * transaction is aborted, commands ignored until end of transaction block".
 *
 * SKIPPED on SQLite, which is the main suite. Run through phpunit.pgsql.xml.
 */
final class AuditWritePostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The abort-on-error behaviour under test exists only on Postgres.');
        }
    }

    public function test_a_failed_audit_write_does_not_take_the_write_after_it_down(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'pg-audit.myshopify.com',
            'name' => 'PG audit',
            'status' => Shop::STATUS_INSTALLED,
        ]);

        // Force the audit insert to fail the way a real constraint violation
        // would: `kind` is a bounded column, so a value past its length errors in
        // the database rather than in PHP.
        $tooLong = str_repeat('x', 5000);

        DB::transaction(function () use ($shop, $tooLong): void {
            Timeline::record(
                kind: $tooLong,
                details: ['probe' => true],
                shopId: (int) $shop->getKey(),
            );

            // THE ASSERTION. Without the savepoint this line throws
            // "current transaction is aborted", and in the charge pipeline the
            // line in this position is the ledger write for money that has
            // already moved.
            $survivor = Shop::query()->whereKey($shop->getKey())->first();

            $this->assertNotNull(
                $survivor,
                'The audit failure poisoned the caller\'s transaction: every statement after it '
                .'now fails, including the one recording the money.',
            );
        });

        $this->assertSame(
            0,
            ActivityEvent::query()->where('shop_id', $shop->getKey())->count(),
            'The audit row itself is correctly absent — it is the only thing that was lost.',
        );
    }
}
