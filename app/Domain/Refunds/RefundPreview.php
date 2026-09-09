<?php

namespace App\Domain\Refunds;

use App\Domain\Invoicing\InvoiceProviderFactory;
use App\Domain\Refunds\Models\RefundRequest;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Support\Tenant;
use Throwable;

/**
 * "What will happen if I press this?" — computed BEFORE anything moves.
 *
 * A refund is the one screen in this app where a merchant is asked to approve
 * something irreversible, and the honest version of that question has to name
 * all four consequences: how much money leaves and through whose rail, what the
 * store will show afterwards, which document gets issued (or that none will,
 * because this shop has no invoicing connected), and what happens to the
 * subscription the order started.
 *
 * Every answer here is the SAME answer the orchestrator will act on — the
 * refundable figure comes from the same resolver, the rail from the same
 * decision, the document question from the same factory. A summary computed a
 * second way is a summary that eventually lies.
 */
final readonly class RefundPreview
{
    private function __construct(
        public string $rail,
        public float $refundable,
        public string $currency,
        public int $chargeCount,
        public bool $storeConnected,
        public bool $invoicingConnected,
        public ?int $planId,
        public ?string $orderId,
        public ?int $ledgerId,
    ) {}

    /**
     * Build the preview for a target: one order, optionally narrowed to one
     * charge the merchant picked.
     */
    public static function for(Shop $shop, ?string $orderId, ?int $ledgerId = null): self
    {
        $probe = new RefundRequest;
        $probe->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'mode' => RefundRequest::MODE_REFUND_FULL,
            'external_order_id' => $orderId,
            'ledger_id' => $ledgerId,
            'currency' => (string) config('payplus.currency', 'ILS'),
        ]);

        $target = app(RefundTargetResolver::class)->for($shop, $probe);

        return new self(
            rail: $target->rail,
            refundable: $target->refundable,
            currency: $target->currency,
            chargeCount: $target->charges->count(),
            storeConnected: StoreRefunderFactory::for($shop) !== null,
            invoicingConnected: self::hasInvoicing($shop),
            planId: self::planIdFor($shop, $target->charges->first()),
            orderId: $orderId,
            ledgerId: $ledgerId,
        );
    }

    public function hasAnythingToRefund(): bool
    {
        return $this->refundable > 0;
    }

    /** The translation key for the rail's name, for the summary lines. */
    public function railKey(): string
    {
        return 'refunds.rail.'.$this->rail;
    }

    /**
     * Whether a live plan hangs off this order — the difference between
     * "cancelling stops the billing" and "there is nothing to stop".
     */
    private static function planIdFor(Shop $shop, ?PaymentLedger $charge): ?int
    {
        if ($charge === null) {
            return null;
        }

        $planId = $charge->plan_id !== null ? (int) $charge->plan_id : null;
        if ($planId === null) {
            return null;
        }

        return Tenant::run($shop, static fn (): ?int => InstallmentPlan::query()
            ->whereKey($planId)
            ->exists() ? $planId : null);
    }

    /**
     * Is there a provider that would issue the credit note? Wrapped, because a
     * merchant's misconfigured invoicing settings must not stop them refunding
     * somebody — the worst honest answer here is "no document".
     */
    private static function hasInvoicing(Shop $shop): bool
    {
        try {
            return InvoiceProviderFactory::for($shop) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
