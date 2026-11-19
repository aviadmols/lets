<?php

namespace App\Domain\Invoicing;

use App\Models\IssuedDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A credit note that came BEFORE the sale it credits, sent again once the sale exists.
 *
 * The order's invoice waits (OrderDocumentHold — six minutes for a thank-you offer to
 * join it). A refund inside that window asks for its credit note at once, finds no sale
 * document to name, and the provider refuses it before any HTTP call
 * (`missing_linked_document`). Nothing was created, so nothing is at risk — but nothing
 * ever asked again either: the customer kept a tax invoice for money they had back, and
 * the books declared income that was refunded. Three such on one store in two weeks.
 *
 * So when a SALE document issues, the credit notes on the same order (or the same money
 * movement) that failed for exactly that reason are re-queued through
 * DocumentReconciliationService::retry() — the very path the Invoices screen's retry
 * button takes, with its walls: only a failure that proves nothing was created, only a
 * row that can be rebuilt faithfully, reusing its own row by its own key.
 *
 * Only that one failure code. A credit note that failed for any other reason waits for
 * a person, as before.
 */
final class WaitingCreditNotes
{
    // === CONSTANTS ===
    /** The provider's "this credit note names no document" refusal (GreenInvoiceProvider). */
    public const FAILURE_CODE = 'missing_linked_document';

    /** More than any real order collects; a bound on one sale's fan-out, not a target. */
    private const MAX_PER_SALE = 20;

    public function __construct(
        private readonly DocumentReconciliationService $reconciliation,
    ) {}

    /**
     * Re-queue the credit notes that were waiting for this sale. Returns how many.
     */
    public function releaseFor(IssuedDocument $sale): int
    {
        $context = DocumentContext::tryFrom((string) $sale->context);
        if ($context === null || $context->isCredit() || ! $sale->isIssued()) {
            return 0;
        }

        $orderId = trim((string) $sale->external_order_id);
        $ledgerId = $sale->ledger_id !== null ? (int) $sale->ledger_id : null;
        if ($orderId === '' && $ledgerId === null) {
            return 0;
        }

        $released = 0;

        foreach ($this->waitingIds((int) $sale->shop_id, $orderId, $ledgerId) as $id) {
            if ($this->release((int) $sale->shop_id, $id)) {
                $released++;
            }
        }

        if ($released > 0) {
            Log::info('invoicing.credit_notes_released', [
                'shop_id' => (int) $sale->shop_id,
                'sale_document_id' => (int) $sale->getKey(),
                'released' => $released,
            ]);
        }

        return $released;
    }

    /** @return list<int> */
    private function waitingIds(int $shopId, string $orderId, ?int $ledgerId): array
    {
        return IssuedDocument::acrossAllTenants()
            ->where('shop_id', $shopId)
            ->whereIn('context', [DocumentContext::REFUND->value, DocumentContext::CANCELLATION->value])
            ->where('status', IssuedDocument::STATUS_FAILED)
            ->where('failure_code', self::FAILURE_CODE)
            ->where(function ($q) use ($orderId, $ledgerId): void {
                if ($orderId !== '') {
                    $q->orWhere('external_order_id', $orderId);
                }
                if ($ledgerId !== null) {
                    $q->orWhere('ledger_id', $ledgerId);
                }
            })
            ->orderBy('id')
            ->limit(self::MAX_PER_SALE)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * One credit note, under a row lock: two sales on the same order issuing at once
     * (the order's invoice and an offer's) must not both re-queue it. The second waits,
     * then finds the row no longer failed and leaves it.
     */
    private function release(int $shopId, int $id): bool
    {
        return (bool) DB::transaction(function () use ($shopId, $id): bool {
            $row = IssuedDocument::acrossAllTenants()
                ->where('shop_id', $shopId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($row === null
                || $row->status !== IssuedDocument::STATUS_FAILED
                || $row->failure_code !== self::FAILURE_CODE) {
                return false;
            }

            return $this->reconciliation->retry($row)['ok'];
        });
    }
}
