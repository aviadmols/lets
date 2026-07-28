<?php

namespace App\Domain\ShopifySubscriptions\Jobs;

use App\Domain\ShopifySubscriptions\ContractBackfill;
use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pull ONE shop's existing subscription contracts into the mirror.
 *
 * shop_id is carried EXPLICITLY and TenantContext binds it for the job's
 * lifetime — the backfill writes through ContractMirror, whose lookup is
 * tenant-scoped and fails closed, so an unbound run would find no existing row
 * and try to insert a duplicate against the (shop_id, shopify_gid) unique index.
 * Binding here is what makes a re-run an update instead of a collision.
 *
 * ShouldBeUnique per shop: two overlapping backfills would read the same pages
 * and write the same rows to no purpose.
 */
final class BackfillContractsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_SYNC;

    /** ShouldBeUnique lock TTL (seconds) — released when the job completes. */
    public int $uniqueFor = 900;

    /**
     * One attempt. The two ways this fails are a pending scope approval and a
     * dead token; neither is fixed by retrying a minute later, and both are
     * fixed by running the command again once they are resolved.
     */
    public int $tries = 1;

    public function __construct(public readonly int $shopId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return 'shop:'.$this->shopId.':subscriptions:backfill';
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(ContractBackfill $backfill): void
    {
        $shop = Shop::query()->find($this->shopId);

        // Rail re-checked at RUN time, like BillingAttemptJob: a job queued while
        // the merchant was on the Shopify rail must not mirror contracts into a
        // shop that has since moved back to PayPlus.
        if ($shop === null || ! $shop->isLive() || ! $shop->usesShopifyPaymentsRail()) {
            return;
        }

        $backfill->run($shop);
    }
}
