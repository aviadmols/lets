<?php

namespace App\Domain\Campaigns\Studio\Jobs;

use App\Domain\Ai\AiResult;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Studio\DocumentService;
use App\Domain\Campaigns\Studio\Models\AiChatMessage;
use App\Domain\Campaigns\Studio\StudioChat;
use App\Models\PlatformAiSettings;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One assistant turn, off the request. A model call is 10-60 seconds; a
 * Livewire request is not — the screen polls the row this job moves.
 *
 * IDEMPOTENCY IS THE CLAIM: claimRunning() is an atomic pending → running
 * move, so a redelivered job finds the row taken and exits silently. tries=1 —
 * a retry would bill a second model call for a turn the merchant may have
 * stopped caring about; the chat's own "try again" is the retry.
 *
 * Every precondition re-checked at RUN time (the house job law): the kill
 * switch flipped while queued, the campaign sent while queued — each fails the
 * row with a typed reason the card can say out loud, never a silent nothing.
 */
final class RunStudioChatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    /** No money moves here — the sync lane, with the other campaign work. */
    public const QUEUE = TenantContext::QUEUE_SYNC;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $shopId,
        public readonly int $campaignId,
        public readonly int $messageId,
    ) {
        $this->onQueue(self::QUEUE);
        $this->timeout = (int) config('ai.chat.job_timeout', 120);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(StudioChat $chat, DocumentService $documents): void
    {
        $message = AiChatMessage::query()->find($this->messageId);
        if ($message === null) {
            return;
        }

        // The claim IS the idempotency — a second delivery finds it taken.
        if (! $message->claimRunning()) {
            return;
        }

        $campaign = EmailCampaign::query()->find($this->campaignId);

        if ($campaign === null || ! $campaign->isStudio() || ! $campaign->isEditable()) {
            $message->fail('campaign_gone');

            return;
        }

        if (! PlatformAiSettings::current()->isEnabled()) {
            $message->fail(AiResult::FAIL_DISABLED);

            return;
        }

        $document = $documents->documentFor($campaign);
        if ($document === null) {
            $message->fail('campaign_gone');

            return;
        }

        try {
            $chat->run($campaign, $message, $document);
        } catch (Throwable $e) {
            // A bug in orchestration must never leave the screen polling a row
            // that will not move.
            Log::warning('studio.chat.run_failed', [
                'shop_id' => $this->shopId,
                'message_id' => $this->messageId,
                'error' => $e->getMessage(),
            ]);

            $message->fail(AiResult::FAIL_HTTP);
        }
    }
}
