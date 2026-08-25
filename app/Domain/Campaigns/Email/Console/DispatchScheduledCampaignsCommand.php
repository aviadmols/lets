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
}
