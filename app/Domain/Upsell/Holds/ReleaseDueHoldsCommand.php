<?php

namespace App\Domain\Upsell\Holds;

use App\Domain\Upsell\Models\UpsellOrderHold;
use App\Domain\Upsell\Models\UpsellSetting;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Let go of every order whose add-on window has closed.
 *
 * A SCANNER, not a delayed job per order — the DispatchDuePlansCommand pattern,
 * for the same reasons. A merchant who shortens the window sees it apply on the
 * next pass; a queue that loses a message costs a late release rather than an
 * order held forever; and an operator can see the backlog by reading a table.
 *
 * Overlapping runs are harmless: release() is idempotent and the row moves to
 * `released` under a status check, so the second pass finds nothing to do.
 */
final class ReleaseDueHoldsCommand extends Command
{
    // === CONSTANTS ===
    protected $signature = 'upsell:release-holds {--chunk=100}';

    protected $description = 'Release every order whose post-purchase add-on window has closed.';

    /** Heartbeat for liveness monitoring, matching the other scanners. */
    private const HEARTBEAT_KEY = 'upsell:release_holds:last_run_at';

    /**
     * A hold this old is released without further thought.
     *
     * If the scheduler was down for a day, the shopper's window closed a day ago
     * whatever our table says, and the merchant's goods have been sitting still.
     * There is no case where waiting longer helps.
     */
    private const STALE_HOURS = 24;

    public function __construct(private readonly OrderHoldService $holds)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $released = 0;
        $notified = 0;

        // AUDITED cross-tenant scan; every action re-binds its own tenant.
        UpsellOrderHold::acrossAllTenants()
            ->where('status', UpsellOrderHold::STATUS_HELD)
            ->where('release_at', '<=', now())
            ->orderBy('id')
            ->chunkById($chunk, function ($holds) use (&$released, &$notified): void {
                foreach ($holds as $hold) {
                    $shop = Shop::query()->find($hold->shop_id);
                    if ($shop === null) {
                        continue;
                    }

                    if ($this->holds->release($shop, $hold)) {
                        $released++;
                        $notified += $this->notify($shop, $hold->refresh()) ? 1 : 0;
                    }
                }
            });

        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());

        $this->info("Released {$released} hold(s); emailed {$notified} shopper(s).");

        return self::SUCCESS;
    }

    /**
     * Email the shopper — ONLY when they actually added something.
     *
     * The platform still sends its own order confirmation, and a second
     * "here is your order" for an order nobody changed is noise a merchant would
     * have to apologise for. An order that gained items is the one case where the
     * first confirmation is now out of date.
     */
    private function notify(Shop $shop, UpsellOrderHold $hold): bool
    {
        if (! $hold->hasAdditions() || $hold->notified_at !== null) {
            return false;
        }

        return Tenant::run($shop, function () use ($shop, $hold): bool {
            if (! UpsellSetting::current()->holdNotify()) {
                return false;
            }

            try {
                $sent = app(OrderUpdatedNotifier::class)->send($shop, $hold);
            } catch (\Throwable $e) {
                // A mail failure must never leave the order held: the release
                // already happened, and this is the notification about it.
                Log::warning('upsell.hold.notify_failed', ['shop_id' => $shop->getKey(), 'message' => $e->getMessage()]);

                return false;
            }

            if ($sent) {
                $hold->forceFill(['notified_at' => now()])->save();
            }

            return $sent;
        });
    }
}
