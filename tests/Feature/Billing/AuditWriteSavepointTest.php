<?php

namespace Tests\Feature\Billing;

use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * AN AUDIT WRITE MUST NOT BE ABLE TO KILL THE MONEY WRITE NEXT TO IT.
 *
 * Timeline::record() and UpsellOfferEvent::record() both swallow their own
 * exceptions, on the stated principle that a failed audit row must never block
 * or roll back a charge. On MySQL and SQLite that principle holds: a failed
 * INSERT is just a failed statement.
 *
 * On POSTGRES — which is what this app runs on in production — it does not. A
 * statement that errors inside a transaction puts the whole transaction into an
 * aborted state, and every statement after it fails with "current transaction is
 * aborted, commands ignored until end of transaction block". So catching the
 * exception changes nothing: the ledger write that follows dies too, and the
 * charge unwinds after the money has already moved.
 *
 * The fix is a SAVEPOINT — a nested transaction around the audit insert, so a
 * failure rolls back to the savepoint and leaves the outer transaction healthy.
 *
 * This test pins the MECHANISM rather than the symptom, which is what makes it
 * driver-agnostic: the suite runs on SQLite, where the symptom cannot be
 * reproduced at all and where a test of the symptom would pass green forever
 * while production stayed broken. AuditWritePostgresTest covers the symptom
 * end-to-end wherever a real Postgres is available.
 */
final class AuditWriteSavepointTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_audit_write_inside_a_transaction_is_wrapped_in_a_savepoint(): void
    {
        $shop = $this->shop();

        $begins = 0;
        Event::listen(TransactionBeginning::class, function () use (&$begins): void {
            $begins++;
        });

        DB::transaction(function () use ($shop, &$begins): void {
            $begins = 0;

            Timeline::record(
                kind: Timeline::KIND_CHARGE_ATTEMPT_STARTED,
                details: ['probe' => true],
                shopId: (int) $shop->getKey(),
            );

            $this->assertGreaterThan(
                0,
                $begins,
                'Timeline::record wrote straight into the caller\'s transaction. On Postgres a '
                .'failure there aborts the whole transaction, and the try/catch around it is '
                .'worthless — the ledger row written next dies with it.',
            );
        });
    }

    /**
     * The savepoint must be RELEASED, leaving the caller exactly where it was —
     * a nested transaction that leaked a level would be its own kind of mess.
     *
     * (The "no caller transaction ⇒ no savepoint" half of the branch cannot be
     * exercised here: RefreshDatabase holds a transaction open around every
     * test, so the suite can never reach a level of zero.)
     */
    public function test_the_caller_transaction_is_left_exactly_as_it_was_found(): void
    {
        $shop = $this->shop();

        DB::transaction(function () use ($shop): void {
            $before = DB::transactionLevel();

            Timeline::record(
                kind: Timeline::KIND_CHARGE_ATTEMPT_STARTED,
                details: ['probe' => true],
                shopId: (int) $shop->getKey(),
            );

            $this->assertSame($before, DB::transactionLevel(), 'The savepoint was not released.');

            // And the caller's transaction is still usable — the whole point.
            $this->assertTrue(Shop::query()->whereKey($shop->getKey())->exists());
        });
    }

    private function shop(): Shop
    {
        return Shop::create([
            'shopify_domain' => 'savepoint.myshopify.com',
            'name' => 'Savepoint',
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }
}
