<?php

namespace App\Domain\Campaigns\Jobs;

use App\Domain\Campaigns\GiftAddressResolver;
use App\Domain\Campaigns\GiftOrderReconciler;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\Shop;
use App\Services\Shopify\Orders\ShopifyGiftOrderService;
use App\Services\WooCommerce\Orders\WooGiftOrderService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Create ONE gift order for ONE recipient.
 *
 * The wall against gifting someone twice is the recipient row, not the platform:
 * neither WooCommerce nor Shopify accepts an idempotency key on order creation, so
 * there is nothing store-side to collapse a duplicate. The row is CLAIMED
 * (pending → creating, atomically) before the API call, and a second delivery of
 * this job finds it already claimed and stops.
 *
 * A row left in `creating` is never picked up again automatically. The call may
 * have reached the store before the worker died, so retrying could ship a second
 * package — the same asymmetry the invoicing module settles with `unresolved`: a
 * missing gift is a button click, a duplicate one costs real money.
 *
 * shop_id is carried EXPLICITLY and TenantContext binds it; nothing here infers a
 * shop from global state.
 */
final class GiftOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    /** No money moves here — this belongs on the sync lane, not the charge lane. */
    public const QUEUE = TenantContext::QUEUE_SYNC;

    /**
     * ShouldBeUnique lock TTL (seconds) — released when the job completes.
     *
     * Generous on purpose: a thousand-recipient campaign is dispatched PACED (see
     * GiftCampaignGenerator::SECONDS_BETWEEN_ORDERS), so the last job waits ~33
     * minutes before it runs. A TTL shorter than that window would expire while the
     * job is still queued and let a second Generate enqueue a duplicate.
     */
    public int $uniqueFor = 3600;

    /**
     * One attempt. A queue-level retry would re-enter a recipient whose order may
     * already exist; re-running is the merchant's explicit decision, through the
     * campaign screen.
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
        return sprintf('shop:%d:gift:%d:rcpt:%d', $this->shopId, $this->campaignId, $this->recipientId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(GiftAddressResolver $addresses): void
    {
        $shop = Shop::query()->find($this->shopId);

        // Preconditions re-checked at RUN time, not at dispatch: a job that sat
        // queued while the merchant disconnected the store must not try to write
        // to it.
        if ($shop === null || ! $shop->isLive()) {
            return;
        }

        $campaign = GiftCampaign::query()->find($this->campaignId);
        $recipient = GiftRecipient::query()->find($this->recipientId);

        if ($campaign === null || $recipient === null) {
            return;
        }

        // Claim it. Losing the race means another delivery of this job is already
        // creating the order — stopping here is the whole duplicate wall.
        if (! $recipient->claim()) {
            return;
        }

        $recipient->refresh();
        $source = null;

        try {
            $resolved = $addresses->resolve($shop, $recipient);

            if ($resolved['address'] === null) {
                // A gift with nowhere to go is not created. The reason is recorded
                // so the merchant can fix it and re-run, rather than wonder.
                $recipient->markSkipped((string) $resolved['reason']);

                return;
            }

            $source = $resolved['source'];

            $orderId = $shop->platform === Shop::PLATFORM_WOOCOMMERCE
                ? app(WooGiftOrderService::class)->create($shop, $campaign, $recipient, $resolved['address'])
                : app(ShopifyGiftOrderService::class)->create($shop, $campaign, $recipient, $resolved['address']);

            if ($orderId === null) {
                // A 2xx with no id in it. The store said yes to something we cannot
                // name, so this is unknown, not refused.
                $this->settleUnknown($shop, $recipient, $source);

                return;
            }

            $recipient->markCreated($orderId, $source);
        } catch (\Throwable $e) {
            Log::warning('campaigns.gift.order_failed', [
                'shop_id' => $this->shopId,
                'recipient_id' => $this->recipientId,
                'status' => $this->statusOf($e),
                'error' => $e->getMessage(),
            ]);

            if ($this->storeRefused($e)) {
                // The store understood the request and said no, so nothing was
                // created and a retry is safe. That is what `failed` means here.
                $recipient->markFailed(GiftRecipient::REASON_API_ERROR);

                return;
            }

            $this->settleUnknown($shop, $recipient, $source);
        } finally {
            $campaign->refresh()->settleStatus();
        }
    }

    /**
     * The outcome is unknown: the store broke, or never answered, AFTER we sent the
     * order. Both platforms create the order first and run the merchant's hooks
     * afterwards, so a fatal in somebody's plugin looks exactly like this — and the
     * order is sitting there.
     *
     * So go and look before deciding. Found → the gift landed. Not found → nobody
     * retries this automatically; a human checks the store.
     */
    private function settleUnknown(Shop $shop, GiftRecipient $recipient, ?string $source): void
    {
        $orderId = app(GiftOrderReconciler::class)->find($shop, $recipient);

        if ($orderId !== null) {
            $recipient->markCreated($orderId, $source);

            return;
        }

        $recipient->markUnresolved();
    }

    /**
     * Did the store REFUSE, or did it break?
     *
     * A 4xx is an answer: the store understood the request and rejected it, so no
     * order exists. A 5xx, a timeout, or a dead connection is not an answer at all
     * — 408 and 429 sit on that side too, because a request that timed out or was
     * throttled may still have been processed.
     */
    private function storeRefused(\Throwable $e): bool
    {
        $status = $this->statusOf($e);

        return $status !== null
            && $status >= 400 && $status < 500
            && ! in_array($status, [408, 429], true);
    }

    private function statusOf(\Throwable $e): ?int
    {
        return $e instanceof RequestException ? $e->response->status() : null;
    }
}
