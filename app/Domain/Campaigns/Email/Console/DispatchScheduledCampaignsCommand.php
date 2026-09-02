<?php

namespace App\Domain\Campaigns\Email\Console;

use App\Domain\Campaigns\Email\EmailCampaignSender;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fires the campaigns whose scheduled time has come.
 *
 * An AUDITED cross-tenant scan — the one legitimate shape for a scheduler that
 * serves every shop: it reads only ids and statuses, then binds each campaign's
 * OWN shop with Tenant::run before the sender touches anything. Streamed with
 * chunkById so a busy minute never loads every campaign at once.
 *
 * One shop's failure never stops the sweep; it is logged and the next campaign
 * runs. The heartbeat is what the observability page reads to say the scheduler
 * is alive.
 */
final class DispatchScheduledCampaignsCommand extends Command
{
    // === CONSTANTS ===
    public const SIGNATURE = 'campaigns:dispatch-scheduled';

    /** Liveness key the observability dashboard reads. */
    public const HEARTBEAT_KEY = 'campaigns.email.dispatch_heartbeat';

    private const CHUNK = 50;

    /**
     * How long a campaign may sit past its time on a shop that cannot send,
     * before we stop looking at it.
     *
     * A campaign whose shop is no longer live is skipped — correctly — but it
     * stays `scheduled`, so this scan reconsiders it EVERY MINUTE, for as long
     * as the row exists. That is a permanent no-op the merchant cannot see and
     * cannot clear. After the grace period it is cancelled with a reason, which
     * is both an honest answer and an end to the loop.
     */
    private const STALE_AFTER_HOURS = 24;

    protected $signature = self::SIGNATURE;

    protected $description = 'Send the email campaigns whose scheduled time has arrived.';

    public function handle(EmailCampaignSender $sender): int
    {
        $now = now();
        $fired = 0;

        EmailCampaign::acrossAllTenants()
            ->where('status', EmailCampaign::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($campaigns) use ($sender, &$fired): void {
                foreach ($campaigns as $campaign) {
                    $shop = Shop::query()->find((int) $campaign->shop_id);

                    if (! $shop instanceof Shop || ! $shop->isLive()) {
                        if ($shop instanceof Shop) {
                            $this->giveUpIfStale($shop, $campaign);
                        }

                        continue;
                    }

                    try {
                        Tenant::run($shop, function () use ($sender, $shop, $campaign): void {
                            // Re-read INSIDE the tenant scope: the row above came
                            // from the cross-tenant scan, and everything the
                            // sender touches must be scoped.
                            $scoped = EmailCampaign::query()->find($campaign->getKey());
                            if ($scoped !== null) {
                                $sender->send($shop, $scoped);
                            }
                        });

                        $fired++;
                    } catch (Throwable $e) {
                        Log::warning('campaigns.email.schedule_failed', [
                            'shop_id' => (int) $campaign->shop_id,
                            'campaign_id' => (int) $campaign->getKey(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        Cache::forever(self::HEARTBEAT_KEY, $now->toIso8601String());

        if ($fired > 0) {
            $this->info(sprintf('Dispatched %d scheduled campaign(s).', $fired));
        }

        return self::SUCCESS;
    }

    /**
     * Stop reconsidering a campaign whose shop has been unable to send it for
     * long enough that it is not going to.
     *
     * Cancelled, not deleted: the merchant asked for this to go out, and a row
     * that quietly vanished would answer nothing. Cancelled says what happened
     * and the campaign can be duplicated into a fresh draft.
     */
    private function giveUpIfStale(Shop $shop, EmailCampaign $campaign): void
    {
        $due = $campaign->scheduled_at;

        if ($due === null || $due->gt(now()->subHours(self::STALE_AFTER_HOURS))) {
            return;
        }

        // Bound, because markCancelled() writes through the tenant scope — the
        // row above came from the cross-tenant scan and would otherwise fail
        // closed and silently, leaving exactly the loop this is here to end.
        $cancelled = Tenant::run($shop, fn (): bool => (bool) EmailCampaign::query()
            ->whereKey($campaign->getKey())
            ->first()
            ?->markCancelled());

        if ($cancelled) {
            Log::warning('campaigns.email.schedule_abandoned', [
                'shop_id' => (int) $campaign->shop_id,
                'campaign_id' => (int) $campaign->getKey(),
                'scheduled_at' => $due->toIso8601String(),
                'reason' => 'shop_not_live',
            ]);
        }
    }
}
