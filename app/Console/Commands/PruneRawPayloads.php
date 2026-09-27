<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Data minimisation for inbound webhooks: blank `webhook_events.raw_payload`
 * once it has done its job.
 *
 * A raw Shopify order payload carries the buyer's name, email, phone and
 * addresses, and was kept forever. The ROW stays (topic, ids, timestamps, HMAC
 * verdict, error — the delivery audit); only the payload goes.
 *
 *   - PROCESSED events: after RETENTION_DAYS. Long enough to investigate a
 *     month-old "why did this order not sync?" with the payload in hand.
 *   - Events never processed: after UNPROCESSED_RETENTION_DAYS — later, because
 *     an unprocessed payload may still be replayed, but not forever.
 *
 * Platform-wide by design (webhook_events is a platform table with shop_id as
 * a column, not a tenant model). Batched so a large backlog never holds one
 * long lock on the table the webhook endpoint writes to.
 */
final class PruneRawPayloads extends Command
{
    // === CONSTANTS ===
    /** Days a PROCESSED webhook keeps its raw payload. */
    public const RETENTION_DAYS = 30;

    /** Days an UNPROCESSED webhook keeps its raw payload. */
    public const UNPROCESSED_RETENTION_DAYS = 90;

    /** Rows blanked per statement. */
    private const BATCH = 500;

    protected $signature = 'privacy:prune-raw-payloads';

    protected $description = 'Blank webhook raw payloads past their retention window (the audit row stays).';

    public function handle(): int
    {
        $processedCutoff = now()->subDays(self::RETENTION_DAYS);
        $anyCutoff = now()->subDays(self::UNPROCESSED_RETENTION_DAYS);
        $total = 0;

        do {
            $ids = WebhookEvent::query()
                ->whereNotNull('raw_payload')
                ->where(fn ($q) => $q
                    ->where(fn ($p) => $p->whereNotNull('processed_at')->where('created_at', '<', $processedCutoff))
                    ->orWhere('created_at', '<', $anyCutoff))
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += WebhookEvent::query()->whereIn('id', $ids)->update(['raw_payload' => null]);
        } while ($ids->count() === self::BATCH);

        $this->info("Blanked {$total} webhook payload(s).");

        return self::SUCCESS;
    }
}
