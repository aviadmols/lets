<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Jobs\GiftOrderJob;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * Turns a campaign's rule into enrolled recipients and dispatches one order job
 * each.
 *
 * Re-running is SAFE and is the intended way to pick up newcomers: enrolment goes
 * through the unique (campaign, source_type, source_id) index, so a subscriber who
 * was already enrolled is silently ignored, and only rows still `pending` get a
 * job. Someone already gifted — or skipped, or failed — is never re-created by a
 * re-run; fixing a failure is a separate, explicit retry.
 */
final class GiftCampaignGenerator
{
    // === CONSTANTS ===
    /** insertOrIgnore chunk — a campaign can enrol thousands at once. */
    private const CHUNK = 500;

    /**
     * Seconds between two order-creation jobs for the same shop.
     *
     * A gift campaign is the only place in this app that creates a THOUSAND orders
     * from one click. Firing them at queue speed would put a small WooCommerce host
     * under a sustained write load it never sees in normal trading — and a store
     * that starts timing out turns confident results into unresolved ones. Paced
     * dispatch keeps a big campaign boring: 1000 recipients spread over ~33
     * minutes, at a rate any shared host serves without noticing.
     */
    public const SECONDS_BETWEEN_ORDERS = 2;

    /** Dispatch in batches so a huge campaign never holds every id in memory. */
    private const DISPATCH_CHUNK = 200;

    public function __construct(private readonly GiftEligibility $eligibility) {}

    /**
     * @return array{enrolled: int, dispatched: int, already: int}
     */
    public function generate(Shop $shop, GiftCampaign $campaign): array
    {
        $rows = $this->eligibility->qualifying((int) $campaign->min_cycles, $campaign);

        $already = $rows->where('already_gifted', true)->count();
        $fresh = $rows->where('already_gifted', false)->values();

        $campaign->forceFill([
            'status' => GiftCampaign::STATUS_GENERATING,
            'generated_at' => now(),
        ])->save();

        $now = now();
        $shopId = (int) $shop->getKey();

        // insertOrIgnore, not create(): the unique index is the wall, and letting
        // the database enforce it means two merchants clicking Generate at the same
        // moment cannot produce two enrolments for one subscriber.
        $fresh->chunk(self::CHUNK)->each(function ($chunk) use ($campaign, $shopId, $now): void {
            GiftRecipient::query()->insertOrIgnore(
                $chunk->map(fn (array $row): array => [
                    'shop_id' => $shopId,
                    'gift_campaign_id' => $campaign->getKey(),
                    'source_type' => $row['source_type'],
                    'source_id' => $row['source_id'],
                    'customer_name' => $row['label'],
                    'customer_email' => $row['email'],
                    'cycles_at_generate' => $row['cycles'],
                    'status' => GiftRecipient::STATUS_PENDING,
                    'currency' => (string) $campaign->currency,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        });

        // Dispatch only what is still PENDING — this is what makes a re-run add
        // newcomers without touching anyone already served. Chunked, so a campaign
        // of thousands never loads every id at once.
        $dispatched = 0;

        GiftRecipient::query()
            ->where('gift_campaign_id', $campaign->getKey())
            ->where('status', GiftRecipient::STATUS_PENDING)
            ->orderBy('id')
            ->chunkById(self::DISPATCH_CHUNK, function ($chunk) use ($shopId, $campaign, &$dispatched): void {
                foreach ($chunk as $recipient) {
                    // Spread over time rather than all at once — see
                    // SECONDS_BETWEEN_ORDERS. The delay grows with position, so
                    // recipient 1 goes now and recipient 1000 in half an hour.
                    $delay = $dispatched * self::SECONDS_BETWEEN_ORDERS;
                    $recipientId = (int) $recipient->getKey();

                    // afterCommit: the worker must not read a recipient row that
                    // this transaction has not committed yet.
                    DB::afterCommit(function () use ($shopId, $campaign, $recipientId, $delay): void {
                        GiftOrderJob::dispatch($shopId, (int) $campaign->getKey(), $recipientId)
                            ->delay(now()->addSeconds($delay));
                    });
                    $dispatched++;
                }
            });

        // Nothing to do at all → the campaign is already finished.
        if ($dispatched === 0) {
            $campaign->refresh()->settleStatus();
        }

        return [
            'enrolled' => $fresh->count(),
            'dispatched' => $dispatched,
            'already' => $already,
        ];
    }
}
