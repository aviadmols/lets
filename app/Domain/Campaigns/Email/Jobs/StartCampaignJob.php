<?php

namespace App\Domain\Campaigns\Email\Jobs;

use App\Domain\Campaigns\Email\EmailCampaignSender;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Build a campaign's audience and start it sending.
 *
 * WHY THIS IS NOT DONE IN THE MERCHANT'S REQUEST. Starting a campaign means
 * resolving the audience across three rails, enrolling every one of them, and
 * dispatching a paced job per person. On a shop with a few dozen subscribers
 * that is instant; on the shops this app is built for — thousands each — it is
 * a request that runs for minutes and then times out somewhere in the middle,
 * leaving the campaign claimed as `sending` with only part of its audience
 * enrolled and the merchant staring at a browser error.
 *
 * The claim is what makes moving it here safe: EmailCampaignSender::send()
 * begins by atomically claiming the campaign for sending, so a second click
 * while this job is queued finds it already claimed and does nothing.
 *
 * ShouldBeUnique on the campaign is the belt: a merchant who clicks Send twice
 * before the worker picks it up enqueues one job, not two.
 *
 * shop_id is carried EXPLICITLY and TenantContext binds it for the job lifetime.
 */
final class StartCampaignJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    /** No money moves here — the sync lane. */
    public const QUEUE = TenantContext::QUEUE_SYNC;

    /** ShouldBeUnique lock TTL (seconds) — released when the job completes. */
    public int $uniqueFor = 1800;

    /**
     * One attempt. A retry would re-enter a campaign that is already `sending`
     * and part-enrolled; re-running is the merchant's explicit decision (Send
     * again enrols only who is missing — the unique index sees to that).
     */
    public int $tries = 1;

    /** Audience resolution over three rails is not a five-second job. */
    public int $timeout = 600;

    public function __construct(
        public readonly int $shopId,
        public readonly int $campaignId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return sprintf('shop:%d:campaign:%d:start', $this->shopId, $this->campaignId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(EmailCampaignSender $sender): void
    {
        $shop = Shop::query()->find($this->shopId);
        $campaign = EmailCampaign::query()->find($this->campaignId);

        if (! $shop instanceof Shop || ! $shop->isLive() || $campaign === null) {
            return;
        }

        try {
            $sender->send($shop, $campaign);
        } catch (Throwable $e) {
            Log::error('campaigns.email.start_failed', [
                'shop_id' => $this->shopId,
                'campaign_id' => $this->campaignId,
                'error' => $e->getMessage(),
            ]);

            // The campaign was claimed as `sending` before the failure. Settle it
            // against the recipients that DID enrol, so it does not sit claimed
            // forever with no job coming — a merchant must be able to send again.
            $campaign->refresh()->settleStatus();

            throw $e;
        }
    }
}
