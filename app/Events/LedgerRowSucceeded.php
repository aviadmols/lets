<?php

namespace App\Events;

use App\Models\PaymentLedger;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Money we recorded actually landed — fired by Ledger::transition the moment a
 * row ENTERS `succeeded`, and only then.
 *
 * Why here and not on the callers: `Ledger::transition` is the ONE funnel every
 * PayPlus-side success passes through — a plain WooCommerce gateway order, a
 * deposit, an installment, a recurring cycle, an upsell. A listener on this
 * event sees all of them without each caller remembering to announce itself.
 *
 * shop_id is carried explicitly (alongside the row) so a queued listener binds
 * the right tenant instead of trusting whatever global state it woke up in.
 * Listeners are strictly OBSERVERS: nothing here may throw back into the charge
 * path — money must not fail because a side effect did.
 */
final class LedgerRowSucceeded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $shopId,
        public readonly PaymentLedger $row,
    ) {}
}
