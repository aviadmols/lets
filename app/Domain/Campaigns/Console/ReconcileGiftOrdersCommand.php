<?php

namespace App\Domain\Campaigns\Console;

use App\Domain\Campaigns\GiftOrderReconciler;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Console\Command;

/**
 * Go and look for gift orders whose fate the app is unsure of.
 *
 * A store that answers 500 — or times out — after creating the order leaves a
 * recipient marked `failed` or `unresolved` while a real order sits in the store.
 * `failed` is the dangerous one: it OFFERS a retry, and taking it ships a second
 * package.
 *
 * This settles those rows against what the store actually holds. It only ever
 * moves a row to `created`; it never creates, deletes or retries anything.
 */
final class ReconcileGiftOrdersCommand extends Command
{
    // === CONSTANTS ===
    protected $signature = 'gifts:reconcile
        {--shop= : limit to one shop id}
        {--dry-run : report what would change, write nothing}';

    protected $description = 'Match gift recipients with unclear outcomes against the orders in the store';

    /** The states where an order may exist without us having recorded it. */
    private const UNCERTAIN = [
        GiftRecipient::STATUS_FAILED,
        GiftRecipient::STATUS_UNRESOLVED,
        GiftRecipient::STATUS_CREATING,
    ];

    public function handle(GiftOrderReconciler $reconciler): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $shops = Shop::query()
            ->when($this->option('shop'), fn ($q, $id) => $q->whereKey((int) $id))
            ->get();

        $found = 0;
        $checked = 0;

        foreach ($shops as $shop) {
            Tenant::run($shop, function () use ($shop, $reconciler, $dryRun, &$found, &$checked): void {
                $recipients = GiftRecipient::query()
                    ->whereIn('status', self::UNCERTAIN)
                    ->whereNull('external_order_id')
                    ->orderBy('id')
                    ->get();

                foreach ($recipients as $recipient) {
                    $checked++;
                    $orderId = $reconciler->find($shop, $recipient);

                    if ($orderId === null) {
                        // No order found. A `failed` row stays retryable; anything
                        // else stays waiting for a human. Never guessed either way.
                        $this->line(sprintf(
                            '  shop %d · recipient %d (%s) — no order found, left as %s',
                            $shop->getKey(), $recipient->getKey(), $recipient->label(), $recipient->status,
                        ));

                        continue;
                    }

                    $found++;
                    $this->info(sprintf(
                        '  shop %d · recipient %d (%s) — order %s exists%s',
                        $shop->getKey(), $recipient->getKey(), $recipient->label(), $orderId,
                        $dryRun ? ' [dry run]' : ', recording it',
                    ));

                    if (! $dryRun) {
                        $recipient->markCreated($orderId, $recipient->address_source);
                        $recipient->campaign?->refresh()->settleStatus();
                    }
                }
            });
        }

        $this->newLine();
        $this->info(sprintf('Checked %d recipient(s); %d had an order in the store.', $checked, $found));

        // Settle campaigns whose recipients all reached a terminal state.
        if (! $dryRun) {
            foreach ($shops as $shop) {
                Tenant::run($shop, function (): void {
                    GiftCampaign::query()
                        ->where('status', GiftCampaign::STATUS_GENERATING)
                        ->get()
                        ->each(fn (GiftCampaign $c) => $c->settleStatus());
                });
            }
        }

        return self::SUCCESS;
    }
}
