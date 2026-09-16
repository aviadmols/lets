<?php

namespace App\Domain\Installments\Jobs;

use App\Domain\Installments\PlanActivation;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Support\Tenant;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Email a just-paid subscription's activation link to its customer.
 *
 * Queued, because it is dispatched from inside the checkout callback and an email
 * provider's latency has no business on a payment path. The shop travels explicitly and
 * is bound here; the plan is re-read, so a plan activated or cancelled in the meantime
 * is simply not emailed.
 */
final class SendPlanActivationLinkJob implements ShouldQueue
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
        public readonly int $planId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(PlanActivation $activation): void
    {
        $shop = Shop::query()->find($this->shopId);

        if (! $shop instanceof Shop) {
            return;
        }

        Tenant::run($shop, function () use ($shop, $activation): void {
            $plan = InstallmentPlan::query()->find($this->planId);

            if ($plan !== null) {
                $activation->send($shop, $plan);
            }
        });
    }
}
