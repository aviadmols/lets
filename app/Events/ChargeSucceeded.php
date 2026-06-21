<?php

namespace App\Events;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by ChargeOrchestrator AFTER a successful charge's ledger row + Timeline
 * event are written (money truth FIRST, notification second). The
 * SendChargeSucceededNotification listener turns this into the welcome / payment-
 * received email.
 *
 * shop_id is carried EXPLICITLY so the listener binds the right tenant without
 * inferring it from global state (the listener may run on a queue/worker that
 * just handled another shop).
 */
final class ChargeSucceeded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $shopId,
        public readonly InstallmentPlan $plan,
        public readonly InstallmentPayment $payment,
        /** True only for the FIRST successful charge on the plan (welcome email). */
        public readonly bool $isFirstPayment = false,
        /** True when this charge completed an installments plan. */
        public readonly bool $isFinal = false,
    ) {}
}
