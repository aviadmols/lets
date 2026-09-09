<?php

namespace App\Domain\Refunds;

use App\Domain\Lifecycle\OrderRefundService;
use App\Domain\Refunds\Models\RefundRequest;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * "Whose money is this, and who can give it back?"
 *
 * Answered from the LEDGER first, because that is the only record of money this
 * app itself moved. Every charge it made — a deposit, an installment, a
 * recurring cycle, an accepted upsell, an account-area add-on, a WooCommerce
 * checkout paid on the PayPlus page — carries a `payplus_transaction_uid`, and
 * those all go back the same way.
 *
 * An order with NO ledger rows was paid by somebody else's rail: a Shopify
 * Payments contract's cycle order, or a WooCommerce order paid with PayPal in the
 * `all_orders` scope. Reversing those means asking the STORE to refund, never
 * PayPlus — a PayPlus call there would refuse (no transaction) or, worse,
 * refund a transaction that was not this order's.
 *
 * Tenant-bound by the caller; every query here rides the models' global scope.
 */
final class RefundTargetResolver
{
    // === CONSTANTS ===
    /** A charge with no transaction uid cannot be reversed at the gateway. */
    private const REQUIRES_UID = true;

    public function __construct(private readonly OrderRefundService $orders) {}

    public function for(Shop $shop, RefundRequest $request): RefundTarget
    {
        $fallbackCurrency = (string) ($request->currency ?: config('payplus.currency', 'ILS'));

        $charges = $this->chargesFor($shop, $request);

        if ($charges->isEmpty()) {
            // No charge of OURS. Either nothing was ever paid, or the money went
            // through the store's own rail. R4 answers the second case; until
            // then the honest answer is "we cannot move this money", and the
            // drawer says so rather than pretending.
            return RefundTarget::nothing($fallbackCurrency);
        }

        $refundable = 0.0;
        foreach ($charges as $charge) {
            $refundable = round($refundable + RefundTarget::remainingOn($charge), 2);
        }

        return new RefundTarget(
            rail: RefundRequest::RAIL_PAYPLUS,
            charges: $charges,
            refundable: $refundable,
            currency: (string) ($charges->first()->currency ?: $fallbackCurrency),
        );
    }

    /**
     * The charges this request may touch.
     *
     * A request SCOPED to one charge touches that one and no other — "refund
     * this cycle" must not quietly reverse the deposit that shares its order.
     * An unscoped request takes every succeeded charge on the order, which is
     * how a checkout and the upsell that followed it come back together.
     *
     * @return Collection<int, PaymentLedger>
     */
    private function chargesFor(Shop $shop, RefundRequest $request): Collection
    {
        $ledgerId = $request->ledger_id !== null ? (int) $request->ledger_id : null;

        if ($ledgerId !== null) {
            $row = Tenant::run($shop, static fn () => PaymentLedger::query()
                ->where('status', PaymentLedger::STATUS_SUCCEEDED)
                ->whereKey($ledgerId)
                ->first());

            return $this->refundable($row === null ? collect() : collect([$row]));
        }

        $orderId = trim((string) ($request->external_order_id ?? ''));

        if ($orderId === '') {
            return collect();
        }

        return $this->refundable($this->orders->chargesFor($shop, $orderId));
    }

    /**
     * Drop what cannot be reversed: a charge with nothing left on it (successive
     * partial refunds have already taken it all) and a charge with no gateway
     * transaction to name. Both would otherwise be counted into the "refundable"
     * figure the merchant is shown, and then refused one by one at the gateway.
     *
     * @param  Collection<int, PaymentLedger>  $charges
     * @return Collection<int, PaymentLedger>
     */
    private function refundable(Collection $charges): Collection
    {
        return $charges
            ->filter(static fn (PaymentLedger $c): bool => RefundTarget::remainingOn($c) > 0)
            ->filter(static fn (PaymentLedger $c): bool => ! self::REQUIRES_UID
                || trim((string) ($c->payplus_transaction_uid ?? '')) !== '')
            ->values();
    }
}
