<?php

namespace App\Domain\Refunds;

use App\Domain\Lifecycle\OrderRefundService;
use App\Domain\Refunds\Models\RefundRequest;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /** Who moves the money when this app never charged the order. */
    private const DELEGATED_RAIL_FOR = [
        Shop::PLATFORM_SHOPIFY => RefundRequest::RAIL_SHOPIFY_NATIVE,
        Shop::PLATFORM_WOOCOMMERCE => RefundRequest::RAIL_WOO_GATEWAY,
    ];

    public function __construct(private readonly OrderRefundService $orders) {}

    public function for(Shop $shop, RefundRequest $request): RefundTarget
    {
        $fallbackCurrency = (string) ($request->currency ?: config('payplus.currency', 'ILS'));

        $charges = $this->chargesFor($shop, $request);

        if ($charges->isEmpty()) {
            // No charge of OURS: either nothing was ever paid, or the money went
            // through the STORE's own rail — a Shopify-Payments contract's cycle
            // order, a WooCommerce order paid with PayPal. Ask the store.
            return $this->fromStore($shop, $request, $fallbackCurrency);
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
     * The rail for an order this app never charged.
     *
     * The STORE is asked how much of it is still refundable, because the ledger
     * knows nothing about it and a number we invented would be a number the
     * merchant is invited to authorise. A store that cannot answer — no
     * connection, no such order, a platform refusal — yields "nothing", and the
     * drawer says so instead of offering a refund that would be refused.
     *
     * A CANCELLATION of an unpaid order legitimately lands here with zero
     * refundable, and the orchestrator lets that through: cancelling an order
     * nobody paid for is a real thing to want.
     */
    private function fromStore(Shop $shop, RefundRequest $request, string $fallbackCurrency): RefundTarget
    {
        $orderId = trim((string) ($request->external_order_id ?? ''));
        $refunder = StoreRefunderFactory::for($shop);

        if ($orderId === '' || $refunder === null) {
            return RefundTarget::nothing($fallbackCurrency);
        }

        try {
            $refundable = $refunder->refundableTotal($shop, $orderId);
        } catch (Throwable $e) {
            Log::warning('refunds.target.store_total_failed', [
                'shop_id' => $shop->getKey(),
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return RefundTarget::nothing($fallbackCurrency);
        }

        if ($refundable === null || round($refundable, 2) <= 0) {
            return RefundTarget::nothing($fallbackCurrency);
        }

        return new RefundTarget(
            rail: self::DELEGATED_RAIL_FOR[(string) $shop->platform] ?? RefundRequest::RAIL_NONE,
            // No charges of ours: the store IS the charge, and the orchestrator
            // reads the rail rather than the collection to know that.
            charges: collect(),
            refundable: round($refundable, 2),
            currency: $fallbackCurrency,
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
