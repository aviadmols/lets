<?php

namespace App\Domain\Refunds;

use App\Domain\Refunds\Models\RefundRequest;
use App\Models\PaymentLedger;
use Illuminate\Support\Collection;

/**
 * What a refund request is actually pointed at: which charges can be reversed,
 * how much of them is left, and WHO gives the money back.
 *
 * The rail is the whole question. The same button on the same screen means
 * "ask PayPlus" for a charge this app made, "ask Shopify" for a cycle order of a
 * Shopify-Payments contract, "ask WooCommerce to ask PayPal" for a store order
 * paid elsewhere, and "move no money at all" when a merchant cancels an order
 * nobody ever paid for. Getting that wrong is either a double refund or a
 * shopper who is never made whole.
 */
final readonly class RefundTarget
{
    /**
     * @param  Collection<int, PaymentLedger>  $charges  oldest first, as the ledger orders them
     * @param  float  $refundable  the sum still refundable across those charges
     */
    public function __construct(
        public string $rail,
        public Collection $charges,
        public float $refundable,
        public string $currency,
    ) {}

    /** Nothing to reverse — an unpaid order, or one already fully refunded. */
    public static function nothing(string $currency): self
    {
        return new self(RefundRequest::RAIL_NONE, collect(), 0.0, $currency);
    }

    /**
     * Is there nothing to do at all?
     *
     * NOT "are there no charges": a DELEGATED rail has no charges of ours by
     * definition — the store holds the money — and reading an empty collection
     * as "nothing to refund" would silently refuse every Shopify-Payments and
     * PayPal order. What makes a target empty is having nothing left to give
     * back, whoever would give it.
     */
    public function isEmpty(): bool
    {
        return $this->charges->isEmpty() && round($this->refundable, 2) <= 0;
    }

    /** How much is still refundable on one charge. */
    public static function remainingOn(PaymentLedger $charge): float
    {
        return round((float) $charge->amount - (float) ($charge->refunded_amount ?? 0), 2);
    }
}
