<?php

namespace App\Domain\Campaigns\Email\Jobs;

use App\Domain\Campaigns\Email\CampaignLoginLinks;
use App\Domain\Campaigns\Email\CampaignMailVars;
use App\Domain\Campaigns\Email\CampaignUnsubscribeLinks;
use App\Domain\Campaigns\Email\Models\CampaignUnsubscribe;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Mail\CampaignMail;
use App\Mail\Support\CampaignMailer;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
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
 * Send ONE campaign email to ONE person.
 *
 * The wall against writing to somebody twice is the recipient row: an SMTP
 * server accepts a duplicate without a word, so the row is CLAIMED (pending →
 * sending, atomically) before the message is handed to the transport, and a
 * second delivery of this job finds it taken and stops.
 *
 * Every precondition is re-checked at RUN time, not at dispatch: a campaign
 * cancelled while the queue drained must not keep sending, a shop disconnected
 * an hour ago must not have mail sent on its behalf, and somebody who
 * unsubscribed between enrolment and delivery must not be written to at all.
 *
 * THE LOGIN TOKEN IS MINTED HERE, and only if the body actually asks for one —
 * a credential nobody will click should not exist. If the send then fails, the
 * token it minted is revoked in the same breath: an unsent email's link must
 * not be live in a log somewhere.
 *
 * shop_id is carried EXPLICITLY and TenantContext binds it; the mailer is built
 * per shop (CampaignMailer) rather than by mutating global config, so a worker
 * that just handled shop B cannot send shop A's mail through B's relay.
 */
final class SendCampaignEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    /** No money moves here — the sync lane, not the charge lane. */
    public const QUEUE = TenantContext::QUEUE_SYNC;

    /**
     * ShouldBeUnique lock TTL (seconds). Generous: a large campaign is dispatched
     * PACED, so the last job may wait a long time before it runs, and a TTL that
     * expired while it queued would let a second Send enqueue a duplicate.
     */
    public int $uniqueFor = 7200;

    /**
     * One attempt. A queue-level retry would re-enter a recipient whose message
     * may already have left; re-sending is the merchant's explicit decision from
     * the campaign screen.
     */
    public int $tries = 1;

    public function __construct(
        public readonly int $shopId,
        public readonly int $campaignId,
        public readonly int $recipientId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return sprintf('shop:%d:campaign:%d:rcpt:%d', $this->shopId, $this->campaignId, $this->recipientId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(CampaignLoginLinks $links, CampaignUnsubscribeLinks $unsubscribes): void
    {
        $campaign = EmailCampaign::query()->find($this->campaignId);
        $recipient = EmailCampaignRecipient::query()->find($this->recipientId);

        if ($campaign === null || $recipient === null) {
            return;
        }

        $shop = Shop::query()->find($this->shopId);

        if (! $shop instanceof Shop || ! $shop->isLive()) {
            $this->settle($campaign, fn () => $recipient->markSkipped(EmailCampaignRecipient::REASON_SHOP_NOT_LIVE));

            return;
        }

        if (! $campaign->isSending()) {
            $this->settle($campaign, fn () => $recipient->markSkipped(EmailCampaignRecipient::REASON_CAMPAIGN_CANCELLED));

            return;
        }

        // Somebody may have unsubscribed since the audience was built.
        if (CampaignUnsubscribe::isSuppressed((string) $recipient->email)) {
            $this->settle($campaign, fn () => $recipient->markSkipped(EmailCampaignRecipient::REASON_UNSUBSCRIBED));

            return;
        }

        // Losing the claim means another delivery of this job is already sending.
        if (! $recipient->claim()) {
            return;
        }

        $recipient->refresh();
        $token = null;

        try {
            $unsubscribeUrl = $unsubscribes->url($recipient);

            // Only mint a credential the body will actually use.
            $loginUrl = '';
            if ($campaign->bodyHasToken(EmailCampaign::TOKEN_LOGIN)) {
                $minted = $links->mint($shop, $campaign, $recipient);
                $token = $minted['token'];
                $loginUrl = $minted['url'];
            }

            $mail = new CampaignMail(
                shop: $shop,
                subjectTemplate: (string) $campaign->subject,
                bodyTemplate: (string) $campaign->body_html,
                vars: CampaignMailVars::for($shop, $recipient, $loginUrl, $unsubscribeUrl),
                unsubscribeUrl: $unsubscribeUrl,
                shopperLocale: $this->localeFor($shop),
                isMarketing: $campaign->isMarketing(),
                textTemplate: (string) ($campaign->body_text ?? ''),
            );

            CampaignMailer::for($shop)->to((string) $recipient->email)->send($mail);

            $recipient->markSent(null);

            Timeline::record(
                kind: Timeline::KIND_CAMPAIGN_EMAIL_SENT,
                details: array_filter([
                    'campaign_id' => (int) $campaign->getKey(),
                    'campaign' => (string) $campaign->name,
                ]),
                planId: $recipient->source_type === EmailCampaignRecipient::SOURCE_PLAN
                    ? (int) $recipient->source_id
                    : null,
                shopId: $this->shopId,
            );
        } catch (Throwable $e) {
            Log::warning('campaigns.email.send_failed', [
                'shop_id' => $this->shopId,
                'campaign_id' => $this->campaignId,
                'recipient_id' => $this->recipientId,
                'error' => $e->getMessage(),
            ]);

            // An email that never left must not leave a live credential behind.
            $token?->revoke();

            $recipient->markFailed(EmailCampaignRecipient::REASON_MAIL_ERROR);
        } finally {
            $campaign->refresh()->settleStatus();
        }
    }

    /** Mark the recipient and close the run if that was the last one open. */
    private function settle(EmailCampaign $campaign, callable $mark): void
    {
        $mark();
        $campaign->refresh()->settleStatus();
    }

    /** The language THIS SHOP'S customers read. */
    private function localeFor(Shop $shop): string
    {
        $settings = MerchantMailSettings::acrossAllTenants()->where('shop_id', $shop->getKey())->first();

        return $settings?->emailLocale() ?? app()->getLocale();
    }
}
