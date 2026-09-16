<?php

namespace App\Domain\ShopifySubscriptions\Jobs;

use App\Domain\ShopifySubscriptions\ContractActivation;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Support\Tenant;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Email a held Shopify Payments contract's activation link to its customer.
 *
 * Queued off the webhook, like its PayPlus sibling is queued off the checkout callback.
 * The shop travels explicitly; the contract is re-read, so one started or cancelled in
 * the meantime is not emailed.
 */
final class SendContractActivationLinkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_SYNC;

    public int $tries = 3;

    /** @var list<int> seconds between attempts */
    public array $backoff = [30, 300];

    public function __construct(
        public readonly int $shopId,
        public readonly int $contractId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(ContractActivation $activation): void
    {
        $shop = Shop::query()->find($this->shopId);

        if (! $shop instanceof Shop) {
            return;
        }

        Tenant::run($shop, function () use ($shop, $activation): void {
            $contract = SubscriptionContract::query()->find($this->contractId);

            if ($contract !== null) {
                $activation->send($shop, $contract);
            }
        });
    }
}
