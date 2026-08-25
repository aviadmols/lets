<?php

namespace App\Domain\Campaigns\Email;

use App\Domain\Campaigns\Email\Jobs\SendCampaignEmailJob;
use App\Domain\Campaigns\Email\Models\CustomerLoginToken;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * Turns a campaign into enrolled recipients and dispatches one send job each.
 *
 * Re-running is SAFE and is how a merchant reaches people who arrived since:
 * enrolment goes through the unique (campaign, email) index, so somebody already
 * enrolled is silently ignored, and only rows still `pending` get a job.
 *
 * Suppressed addresses ARE enrolled — as `skipped/unsubscribed`. Leaving them
 * out entirely would make the merchant's list quietly disagree with the audience
 * count they clicked Send on; a visible skip is the honest version.
 *
 * Dispatch is PACED. A campaign is the only place in this app that hands a
 * thousand messages to one SMTP relay from a single click, and a relay that
 * starts refusing turns a delivered campaign into a half-delivered one.
 */
final class EmailCampaignSender
{
    // === CONSTANTS ===
    /** insertOrIgnore chunk — a campaign can enrol thousands at once. */
    private const CHUNK = 500;

    /** Dispatch in batches so a huge campaign never holds every id in memory. */
    private const DISPATCH_CHUNK = 200;

    public function __construct(private readonly EmailCampaignAudience $audience) {}

    /**
     * Enrol the audience and start sending.
     *
     * @return array{enrolled: int, dispatched: int, suppressed: int, already: int}
     */
    public function send(Shop $shop, EmailCampaign $campaign): array
    {
        // The claim is the wall against two clicks (or a click racing the
        // scheduler) starting the same campaign twice.
        if (! $campaign->claimForSending()) {
            return ['enrolled' => 0, 'dispatched' => 0, 'suppressed' => 0, 'already' => 0];
        }

        $rows = $this->audience->recipients($campaign->audience(), $campaign);

        $already = $rows->where('already_enrolled', true)->count();
        $fresh = $rows->where('already_enrolled', false)->values();
        $suppressed = $fresh->where('unsubscribed', true)->count();

        $now = now();
        $shopId = (int) $shop->getKey();

        // insertOrIgnore, not create(): the unique index is the wall, and letting
        // the database enforce it means two runs cannot produce two enrolments
        // for one person.
        $fresh->chunk(self::CHUNK)->each(function ($chunk) use ($campaign, $shopId, $now): void {
            EmailCampaignRecipient::query()->insertOrIgnore(
                $chunk->map(fn (array $row): array => [
                    'shop_id' => $shopId,
                    'email_campaign_id' => $campaign->getKey(),
                    'email' => $row['email'],
                    'customer_name' => $row['name'],
                    'customer_ref' => $row['customer_ref'],
                    'source_type' => $row['source_type'],
                    'source_id' => $row['source_id'],
                    'status' => $row['unsubscribed']
                        ? EmailCampaignRecipient::STATUS_SKIPPED
                        : EmailCampaignRecipient::STATUS_PENDING,
                    'reason' => $row['unsubscribed'] ? EmailCampaignRecipient::REASON_UNSUBSCRIBED : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        });

        $dispatched = $this->dispatchPending($shopId, $campaign);

        // Nobody to write to at all → the run is already over.
        if ($dispatched === 0) {
            $campaign->refresh()->settleStatus();
        } else {
            $campaign->refreshCounts();
        }

        return [
            'enrolled' => $fresh->count(),
            'dispatched' => $dispatched,
            'suppressed' => $suppressed,
            'already' => $already,
        ];
    }

    /** Put a campaign on the clock. The scheduler picks it up within the minute. */
    public function schedule(EmailCampaign $campaign, \DateTimeInterface $at): bool
    {
        return $campaign->markScheduled($at);
    }

    /** Stop a scheduled or in-flight run. Queued jobs see it and skip. */
    public function cancel(EmailCampaign $campaign): bool
    {
        return $campaign->markCancelled();
    }

    /**
     * Put the failed recipients back in the queue.
     *
     * Only `failed` — a message the transport REFUSED never left. A row still
     * `sending` is not retried: it may already be in somebody's inbox, and a
     * duplicate marketing email is a complaint, not an inconvenience.
     *
     * @return array{requeued: int}
     */
    public function retryFailed(Shop $shop, EmailCampaign $campaign): array
    {
        $requeued = 0;

        EmailCampaignRecipient::query()
            ->where('email_campaign_id', $campaign->getKey())
            ->where('status', EmailCampaignRecipient::STATUS_FAILED)
            ->orderBy('id')
            ->chunkById(self::DISPATCH_CHUNK, function ($chunk) use (&$requeued): void {
                foreach ($chunk as $recipient) {
                    if ($recipient->resetForRetry()) {
                        $requeued++;
                    }
                }
            });

        if ($requeued === 0) {
            return ['requeued' => 0];
        }

        // Back to `sending` so the jobs' own precondition holds.
        EmailCampaign::query()
            ->whereKey($campaign->getKey())
            ->whereIn('status', [EmailCampaign::STATUS_SENT, EmailCampaign::STATUS_SENDING])
            ->update(['status' => EmailCampaign::STATUS_SENDING, 'sent_at' => null, 'updated_at' => now()]);

        $campaign->refresh();
        $this->dispatchPending((int) $shop->getKey(), $campaign);
        $campaign->refreshCounts();

        return ['requeued' => $requeued];
    }

    /**
     * Kill every passwordless link this campaign minted — one switch for the
     * merchant who sent to the wrong list, or whose customer forwarded an email.
     * Both the campaign flag and the rows are set: the flag is instant and
     * catches tokens minted after this call, the rows survive the campaign.
     */
    public function revokeLoginLinks(EmailCampaign $campaign): int
    {
        $campaign->forceFill(['login_links_revoked_at' => now()])->save();

        return CustomerLoginToken::query()
            ->where('email_campaign_id', $campaign->getKey())
            ->whereNull('revoked_at')
            ->whereNull('consumed_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    // === Internals ===

    /**
     * One job per pending recipient, spread over time. The delay grows with
     * position, so recipient 1 goes now and recipient 1000 a few minutes later.
     */
    private function dispatchPending(int $shopId, EmailCampaign $campaign): int
    {
        $perSecond = max(1, (int) config('campaigns.emails_per_second', 2));
        $dispatched = 0;

        EmailCampaignRecipient::query()
            ->where('email_campaign_id', $campaign->getKey())
            ->where('status', EmailCampaignRecipient::STATUS_PENDING)
            ->orderBy('id')
            ->chunkById(self::DISPATCH_CHUNK, function ($chunk) use ($shopId, $campaign, $perSecond, &$dispatched): void {
                foreach ($chunk as $recipient) {
                    $delay = intdiv($dispatched, $perSecond);
                    $recipientId = (int) $recipient->getKey();

                    // afterCommit: the worker must not read a recipient row this
                    // transaction has not committed yet.
                    DB::afterCommit(function () use ($shopId, $campaign, $recipientId, $delay): void {
                        SendCampaignEmailJob::dispatch($shopId, (int) $campaign->getKey(), $recipientId)
                            ->delay(now()->addSeconds($delay));
                    });

                    $dispatched++;
                }
            });

        return $dispatched;
    }
}
