<?php

namespace App\Events;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by ChargeOrchestrator AFTER a failed charge's ledger row + Timeline event
 * are written. The SendChargeFailedNotification listener turns this into the
 * failed-charge email. shop_id is carried EXPLICITLY for tenant binding.
 */
final class ChargeFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $shopId,
        public readonly InstallmentPlan $plan,
        public readonly InstallmentPayment $payment,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        /** True when a retry is scheduled (vs. terminal failure). */
        public readonly bool $willRetry = false,
    ) {}
}
