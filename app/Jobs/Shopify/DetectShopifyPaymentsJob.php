<?php

namespace App\Jobs\Shopify;

use App\Models\Shop;
use App\Services\Shopify\ShopifyPaymentsDetector;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Detect Shopify Payments for ONE shop, off the install path. Runs on the
 * install queue right after the token is captured, so a store that sells
 * through Shopify Payments is tagged (and its PayPlus-only settings hidden)
 * before the merchant first opens the admin.
 *
 * shop_id is carried EXPLICITLY; TenantContext binds and always clears it.
 */
final class DetectShopifyPaymentsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_WEBHOOKS;

    public function __construct(public readonly int $shopId)
    {
        $this->onQueue(self::QUEUE);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(ShopifyPaymentsDetector $detector): void
    {
        $shop = Shop::query()->whereKey($this->shopId)->first();
        if ($shop === null || ! $shop->isLive()) {
            return;
        }

        $detector->detect($shop);
    }
}
