<?php

namespace App\Domain\Invoicing;

use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Models\IssuedDocument;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;

/**
 * The human half of the document safety design.
 *
 * DocumentIssuer deliberately refuses to re-post an attempt whose outcome it
 * never learned (`unresolved`): the invoicing provider has no idempotency key of
 * its own, so a blind retry can mint a SECOND real tax document. That refusal is
 * only safe if a person can finish the job — this service is that seam, and the
 * IssuedDocument screen is its surface.
 *
 * Three deliberate acts, and the difference between them matters:
 *
 *   retry()               — the provider explicitly rejected us, so nothing was
 *                           created. Safe, and merely re-queues.
 *   issueAfterVerifying() — an `unresolved` row where the merchant has LOOKED in
 *                           the provider and confirmed no document exists. This is
 *                           the only path that can duplicate paperwork, so it is
 *                           gated on a human assertion and recorded as one.
 *   recordExisting()      — the merchant looked and FOUND a document. We adopt its
 *                           id rather than issuing another.
 *
 * Every act writes a Timeline row naming the actor, so "who decided to re-issue
 * this" is answerable months later.
 */
final class DocumentReconciliationService
{
    // === CONSTANTS ===
    /** Outcome codes returned to the caller (the screen renders the message). */
    public const OK = 'ok';
    public const NOT_RETRYABLE = 'not_retryable';
    public const NOT_UNRESOLVED = 'not_unresolved';
    public const ALREADY_ISSUED = 'already_issued';
    public const MISSING_DOCUMENT_ID = 'missing_document_id';
    /** The original store-order report is gone, so nothing faithful can be sent. */
    public const NOT_REBUILDABLE = 'not_rebuildable';

    /**
     * Re-queue a document whose failure PROVES nothing was created at the
     * provider. Refuses anything else — including `unresolved`, which must go
     * through issueAfterVerifying() and a human's eyes.
     *
     * @return array{ok: bool, reason: string}
     */
    public function retry(IssuedDocument $document): array
    {
        if ($document->isIssued()) {
            return $this->fail(self::ALREADY_ISSUED);
        }

        if (! $document->isRetryable()) {
            return $this->fail(self::NOT_RETRYABLE);
        }

        if (! $this->isRebuildable($document)) {
            return $this->fail(self::NOT_REBUILDABLE);
        }

        $this->reopen($document);
        $this->dispatch($document);

        Timeline::record(
            kind: Timeline::KIND_DOCUMENT_RETRIED,
            details: [
                'context' => (string) $document->context,
                'failure_code' => (string) $document->failure_code,
            ],
            planId: $document->plan_id !== null ? (int) $document->plan_id : null,
            shopId: (int) $document->shop_id,
        );

        return $this->ok();
    }

    /**
     * Issue an `unresolved` document after the merchant has checked the provider
     * and confirmed none exists.
     *
     * This is the one action in the module that can produce a duplicate tax
     * document if the person is wrong, which is exactly why it cannot be reached
     * by any automatic path and why the Timeline row records the assertion rather
     * than just the outcome.
     *
     * @return array{ok: bool, reason: string}
     */
    public function issueAfterVerifying(IssuedDocument $document): array
    {
        if ($document->isIssued()) {
            return $this->fail(self::ALREADY_ISSUED);
        }

        if ($document->status !== IssuedDocument::STATUS_UNRESOLVED) {
            return $this->fail(self::NOT_UNRESOLVED);
        }

        if (! $this->isRebuildable($document)) {
            return $this->fail(self::NOT_REBUILDABLE);
        }

        $this->reopen($document);
        $this->dispatch($document);

        Timeline::record(
            kind: Timeline::KIND_DOCUMENT_FORCE_ISSUED,
            details: [
                'context' => (string) $document->context,
                // The assertion, not just the act: a human said the provider held
                // no document for this money before we issued a fresh one.
                'verified_absent_by_merchant' => true,
            ],
            planId: $document->plan_id !== null ? (int) $document->plan_id : null,
            shopId: (int) $document->shop_id,
        );

        return $this->ok();
    }

    /**
     * Adopt a document the merchant found at the provider: the earlier attempt DID
     * succeed, we just never learned it. Closes the row without issuing anything.
     *
     * @return array{ok: bool, reason: string}
     */
    public function recordExisting(
        IssuedDocument $document,
        string $providerDocumentId,
        ?string $documentNumber = null,
        ?string $documentUrl = null,
    ): array {
        if ($document->isIssued()) {
            return $this->fail(self::ALREADY_ISSUED);
        }

        $providerDocumentId = trim($providerDocumentId);
        if ($providerDocumentId === '') {
            return $this->fail(self::MISSING_DOCUMENT_ID);
        }

        $document->forceFill([
            'status' => IssuedDocument::STATUS_ISSUED,
            'provider_document_id' => $providerDocumentId,
            'document_number' => $this->blankToNull($documentNumber),
            'document_url' => $this->blankToNull($documentUrl),
            'failure_code' => null,
            'failure_message' => null,
            'issued_at' => now(),
        ])->save();

        Timeline::record(
            kind: Timeline::KIND_DOCUMENT_ISSUED,
            details: [
                'context' => (string) $document->context,
                'document_number' => $document->document_number,
                // Adopted from the provider by a human, not minted by us.
                'reconciled_by_merchant' => true,
            ],
            planId: $document->plan_id !== null ? (int) $document->plan_id : null,
            shopId: (int) $document->shop_id,
        );

        return $this->ok();
    }

    // === Internals ===

    /**
     * Return the row to a clean `pending` state. `attempted_at` MUST be cleared —
     * it is the flag DocumentIssuer reads to decide whether an attempt is already
     * in flight, and leaving it set would make the re-queued job promote the row
     * straight back to `unresolved`.
     */
    private function reopen(IssuedDocument $document): void
    {
        $document->forceFill([
            'status' => IssuedDocument::STATUS_PENDING,
            'attempted_at' => null,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();
    }

    private function dispatch(IssuedDocument $document): void
    {
        IssueDocumentJob::queueAfterCommit(
            shopId: (int) $document->shop_id,
            context: (string) $document->context,
            ledgerId: $document->ledger_id !== null ? (int) $document->ledger_id : null,
            order: $document->ledger_id === null ? $this->orderPayload($document) : null,
            amount: $this->amountOverrideFor($document),
        );
    }

    /**
     * The storefront's ORIGINAL report for a plain store order, kept on the row
     * precisely so a re-issue does not have to invent it.
     *
     * Inventing is not an option here. A missing `payment_gateway` is read by the
     * provider as "LETS card clearing", so a re-issued bank-transfer or
     * cash-on-delivery order would be declared as a CREDIT CARD payment — a false
     * assertion on a tax document, and the exact thing GreenInvoicePaymentType
     * refuses to guess at. An empty customer name would likewise be dropped from
     * the payload and rejected by the provider, leaving a retry button that could
     * never succeed.
     *
     * @return array<string, mixed>|null  null when the report is gone (a row from
     *                                    before this column existed, or a redacted
     *                                    one) — the caller then refuses to re-issue.
     */
    private function orderPayload(IssuedDocument $document): ?array
    {
        $payload = $document->source_payload;

        if (! is_array($payload) || ($payload['order_id'] ?? '') === '') {
            return null;
        }

        return $payload;
    }

    /**
     * Can this document be rebuilt at all? A plan-backed row rebuilds from its
     * ledger row; a store-order row needs its original report. Without either
     * there is nothing faithful to send, and a document that misstates how the
     * money arrived is worse than a missing one.
     */
    private function isRebuildable(IssuedDocument $document): bool
    {
        return $document->ledger_id !== null || $this->orderPayload($document) !== null;
    }

    /**
     * A credit note's key carries its amount, so a re-issue must carry the same
     * amount or it would open a SECOND row instead of reusing this one.
     */
    private function amountOverrideFor(IssuedDocument $document): ?float
    {
        $context = DocumentContext::tryFrom((string) $document->context);

        return $context?->isCredit() === true ? round((float) $document->amount, 2) : null;
    }

    /** @return array{ok: bool, reason: string} */
    private function ok(): array
    {
        return ['ok' => true, 'reason' => self::OK];
    }

    /** @return array{ok: bool, reason: string} */
    private function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
