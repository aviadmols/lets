<?php

namespace App\Domain\ShopifySubscriptions\Jobs;

use App\Domain\ShopifySubscriptions\ContractScheduleAdvancer;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Move a contract past the cycle one succeeded attempt paid (ContractScheduleAdvancer).
 *
 * Dispatched by the success webhook — which Shopify delivers more than once, hence unique
 * per attempt — and by the scanner when it finds a due contract whose cycle is already paid
 * (a webhook that never came, or a contract from before this existed). The shop travels
 * explicitly and TenantContext binds it.
 */
final class AdvanceContractScheduleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_SYNC;

    public int $uniqueFor = 600;

    public int $tries = 3;

    /** @var list<int> seconds between attempts */
    public array $backoff = [60, 600];

    public function __construct(
        public readonly int $shopId,
        public readonly int $attemptId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return sprintf('shop:%d:advance:%d', $this->shopId, $this->attemptId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(ContractScheduleAdvancer $advancer): void
    {
        $shop = Shop::query()->find($this->shopId);
        $attempt = SubscriptionBillingAttempt::query()->find($this->attemptId);

        if ($shop instanceof Shop && $shop->isLive() && $attempt !== null) {
            $advancer->afterPaid($shop, $attempt);
        }
    }
}
