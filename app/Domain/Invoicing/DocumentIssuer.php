<?php

namespace App\Domain\Invoicing;

use App\Domain\Billing\Contracts\DocumentDecision;
use App\Domain\Billing\Contracts\DocumentPolicy;
use App\Domain\Billing\Contracts\DocumentPolicyInput;
use App\Models\InstallmentPlan;
use App\Models\IssuedDocument;
use App\Models\MerchantInvoicingSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\ResponseMasker;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ONE place a document is ever requested. The invoicing analogue of
 * App\Domain\Billing\Ledger, and it enforces the same law:
 *
 *   1. Derive a DETERMINISTIC idempotency key from the money event.
 *   2. Short-circuit if that key already produced an issued document.
 *   3. Ask the CENTRAL DocumentPolicy whether to issue at all, and whether this
 *      document links to a previous one.
 *   4. Open (or reuse) a `pending` issued_documents row BEFORE the HTTP call, and
 *      stamp `attempted_at` immediately before the call itself.
 *   5. Call the provider — which never throws — and transition the SAME row.
 *   6. Write a Timeline event either way.
 *
 * Step 4's stamp is what closes the last double-issue hole: a worker killed between
 * the provider accepting a document and our write leaves a pending row with an
 * attempt on it, and such a row is NEVER re-posted — it becomes `unresolved` for a
 * human to reconcile. Green Invoice has no idempotency key of its own, so a blind
 * retry there would create a second REAL tax document. Losing a document a merchant
 * can re-issue is recoverable; declaring income twice is a VAT correction.
 *
 * Nothing here may disturb the money path. Every entry point is wrapped so a bug
 * in document-building can never propagate into the charge pipeline; the worst
 * case is a `failed` row the merchant can see and retry.
 *
 * Tenant law: every entry point takes shop_id EXPLICITLY and resolves the shop by
 * id. Nothing is inferred from global state, session, domain, or config.
 */
final class DocumentIssuer
{
    // === CONSTANTS ===
    /** Failure codes this service originates (provider codes come from the result). */
    private const ERROR_BUILD_FAILED = 'build_failed';
    private const ERROR_NO_SHOP = 'no_shop';
    /** An attempt was interrupted; whether a document exists is unknown. */
    private const ERROR_OUTCOME_UNKNOWN = 'outcome_unknown';

    /**
     * Issue the document for a money movement recorded in the ledger — deposits,
     * installments, recurring cycles and upsells all arrive here.
     *
     * The idempotency key is the LEDGER's own key, prefixed: one money movement can
     * only ever produce one document, and a re-queued job reuses the same row.
     */
    public function issueForLedger(
        int $shopId,
        int $ledgerId,
        DocumentContext $context,
        ?string $linkedDocumentId = null,
        ?float $amountOverride = null,
    ): ?IssuedDocument {
        try {
            $shop = $this->shop($shopId);
            if ($shop === null) {
                return null;
            }

            $ledger = PaymentLedger::acrossAllTenants()
                ->where('shop_id', $shopId)
                ->whereKey($ledgerId)
                ->first();

            if ($ledger === null) {
                Log::warning('invoicing.issue.ledger_missing', [
                    'shop_id' => $shopId, 'ledger_id' => $ledgerId,
                ]);

                return null;
            }

            // A refund can legitimately happen more than once against one sale (a
            // partial today, another tomorrow), so it keys on the AMOUNT as well —
            // otherwise the second credit note would collide with the first and the
            // merchant's books would under-report the refund.
            $amount = round($amountOverride ?? (float) $ledger->amount, 2);
            $key = $context->isCredit()
                ? self::keyForRefund($ledger, $amount)
                : self::keyForLedger($ledger);

            $existing = $this->findIssued($shopId, $key);
            if ($existing !== null) {
                return $existing;
            }

            $settings = MerchantInvoicingSettings::forShop($shopId);
            $plan = $this->planFor($ledger);

            // The CENTRAL DocumentPolicy still owns "should a document be issued now,
            // and is it linked?" (CLAUDE.md money-safety law). Every path reaches the
            // provider through this check — a merchant on document_mode => none gets
            // no paperwork from ANY hook, not just the charge orchestrator's.
            $decision = $this->decide($shop, $context, (float) $ledger->amount, $plan);
            if (! $decision->shouldIssueNow) {
                return null;
            }

            $request = new IssueDocumentRequest(
                shop: $shop,
                context: $context,
                customer: $plan !== null
                    ? DocumentCustomer::fromPlan($plan)
                    : new DocumentCustomer(name: $ledger->customerLabel()),
                lines: [DocumentLine::single(
                    $this->lineDescriptionFor($context, $ledger, $plan),
                    $amount,
                )],
                amount: $amount,
                currency: (string) ($ledger->currency ?: config('payplus.currency', 'ILS')),
                isPaid: true, // the hooks only fire on a succeeded/refunded ledger row
                linkedDocumentId: $linkedDocumentId
                    ?? $this->linkedDocumentIdFor($shopId, $context, $decision, $ledger, $plan),
                remarks: $this->remarksFor($ledger, $plan),
                sendEmail: $settings->sendsEmailToCustomer(),
            );

            return $this->issue($shop, $context, $key, $request, [
                'ledger_id' => $ledger->getKey(),
                'plan_id' => $ledger->plan_id,
                'external_order_id' => $ledger->shopify_order_id,
            ]);
        } catch (Throwable $e) {
            return $this->recordBuildFailure($shopId, $context, $e);
        }
    }

    /**
     * Issue the document for a PLAIN store order that never touched a LETS plan —
     * the `all_orders` scope. The order payload is reported by the storefront
     * platform (the WooCommerce plugin), already validated by its controller.
     *
     * @param  array{order_id:string, order_number:?string, total:float, currency:string,
     *               customer:array{name:string,email:?string,phone:?string,tax_id:?string},
     *               lines:list<array{description:string,unit_price:float,quantity:int,catalog_number:?string}>,
     *               payment_gateway:?string, card_last4:?string}  $order
     */
    public function issueForPlatformOrder(int $shopId, array $order): ?IssuedDocument
    {
        $context = DocumentContext::PLATFORM_ORDER;

        try {
            $shop = $this->shop($shopId);
            if ($shop === null) {
                return null;
            }

            $orderId = (string) $order['order_id'];
            $key = self::keyForPlatformOrder($shopId, $orderId);

            $existing = $this->findIssued($shopId, $key);
            if ($existing !== null) {
                return $existing;
            }

            $settings = MerchantInvoicingSettings::forShop($shopId);
            $lines = $this->linesFromPlatformOrder($order);

            // Same central policy gate as the ledger path — a merchant who suppressed
            // documents must not have them appear for plain store orders instead.
            if (! $this->decide($shop, $context, round((float) $order['total'], 2), null)->shouldIssueNow) {
                return null;
            }

            $request = new IssueDocumentRequest(
                shop: $shop,
                context: $context,
                customer: new DocumentCustomer(
                    name: (string) ($order['customer']['name'] ?? ''),
                    email: $this->blankToNull((string) ($order['customer']['email'] ?? '')),
                    phone: $this->blankToNull((string) ($order['customer']['phone'] ?? '')),
                    taxId: $this->blankToNull((string) ($order['customer']['tax_id'] ?? '')),
                ),
                lines: $lines,
                amount: round((float) $order['total'], 2),
                currency: (string) ($order['currency'] ?: config('payplus.currency', 'ILS')),
                isPaid: true, // the plugin only reports orders in a merchant-chosen PAID status
                remarks: $this->orderRemarks($order),
                sendEmail: $settings->sendsEmailToCustomer(),
                paymentGateway: $this->blankToNull((string) ($order['payment_gateway'] ?? '')),
                cardLast4: $this->blankToNull((string) ($order['card_last4'] ?? '')),
            );

            return $this->issue($shop, $context, $key, $request, [
                'external_order_id' => $orderId,
                // A plain store order has no ledger row and no plan, so this report
                // is the ONLY record of how the money arrived and who paid. Without
                // it a re-issue would have to invent `payment_gateway`, and the
                // provider reads a missing gateway as a CARD payment — declaring a
                // bank transfer as card on a tax document.
                'source_payload' => $order,
            ]);
        } catch (Throwable $e) {
            return $this->recordBuildFailure($shopId, $context, $e);
        }
    }

    // === Idempotency keys (deterministic — the double-issue wall) ===

    /**
     * One money movement, one document. Deriving the key from the ledger row's own
     * idempotency key means a re-queued job, a replayed webhook, and a retried
     * charge all collapse onto the same issued_documents row.
     */
    public static function keyForLedger(PaymentLedger $ledger): string
    {
        return IssuedDocument::KEY_PREFIX.$ledger->idempotency_key;
    }

    /**
     * A credit note's key. Includes the AMOUNT because one sale can be credited
     * more than once (successive partial refunds) — keying on the ledger row alone
     * would silently swallow every refund after the first.
     */
    public static function keyForRefund(PaymentLedger $ledger, float $amount): string
    {
        return IssuedDocument::KEY_PREFIX.'refund:'.$ledger->getKey()
            .':'.number_format(round($amount, 2), 2, '.', '');
    }

    /**
     * A plain store order has no ledger row, so the order id IS the money event.
     * This is what makes a WooCommerce order flapping processing → completed issue
     * exactly one document.
     */
    public static function keyForPlatformOrder(int $shopId, string $orderId): string
    {
        return IssuedDocument::KEY_PREFIX.'order:'.$shopId.':'.$orderId;
    }

    // === The pipeline ===

    /**
     * Open (or reuse) the row, call the provider, transition the row, write the
     * Timeline event.
     *
     * @param  array<string, mixed>  $linkage  ledger_id / plan_id / external_order_id
     */
    private function issue(
        Shop $shop,
        DocumentContext $context,
        string $key,
        IssueDocumentRequest $request,
        array $linkage,
    ): ?IssuedDocument {
        $shopId = (int) $shop->getKey();

        $provider = InvoiceProviderFactory::for($shop);

        if ($provider === null) {
            // The merchant has invoicing off or unconfigured. Not an error — and NOT
            // recorded as a failed document either, because there is nothing to retry.
            return null;
        }

        $row = $this->open($shopId, $context, $key, $request, $linkage, $provider->name());

        // Either a concurrent worker already issued this document between our check
        // and our write (the unique index caught it), or the row is not retryable —
        // an earlier attempt's outcome is unknown, or its failure does not prove the
        // provider created nothing. Never call the provider in any of those cases.
        if ($row === null || ! $row->isRetryable()) {
            return $row;
        }

        // Claim the attempt BEFORE the HTTP call, ATOMICALLY. The stamp is what
        // stops a redelivered job from posting a SECOND real tax document, but a
        // plain read-then-write is not enough: ShouldBeUnique's lock expires after
        // `uniqueFor`, so on a backed-up queue two workers can hold the same job,
        // both read `attempted_at === null`, and both POST. A conditional UPDATE
        // makes exactly one of them the winner — the loser sees 0 rows and stops.
        if (! $this->claimAttempt($shopId, $row)) {
            Log::info('invoicing.issue.attempt_already_claimed', [
                'shop_id' => $shopId, 'document_id' => $row->getKey(),
            ]);

            return $row->fresh();
        }

        $result = $provider->issue($request);

        if ($result->success) {
            $row->forceFill([
                'status' => IssuedDocument::STATUS_ISSUED,
                'document_type' => $result->documentType,
                'provider_document_id' => $result->documentId,
                'document_number' => $result->documentNumber,
                'document_url' => $result->documentUrl,
                'failure_code' => null,
                'failure_message' => null,
                'raw_response_masked' => ResponseMasker::mask($result->raw),
                'issued_at' => now(),
            ])->save();

            // NO document_url in the Timeline payload. The design-system hard rule
            // (docs/ux/00-design-system.md §4.14) is that the Timeline shows the human
            // label, never the URL — the link lives on the issued_documents row and is
            // surfaced in the ledger screen instead.
            Timeline::record(
                kind: Timeline::KIND_DOCUMENT_ISSUED,
                details: [
                    'context' => $context->value,
                    'document_type' => $result->documentType,
                    'document_number' => $result->documentNumber,
                ],
                planId: $row->plan_id !== null ? (int) $row->plan_id : null,
                shopId: $shopId,
            );

            return $row;
        }

        $row->forceFill([
            'status' => IssuedDocument::STATUS_FAILED,
            'document_type' => $result->documentType,
            'failure_code' => $result->errorCode,
            'failure_message' => $result->errorMessage,
            'raw_response_masked' => ResponseMasker::mask($result->raw),
        ])->save();

        Timeline::record(
            kind: Timeline::KIND_DOCUMENT_FAILED,
            details: [
                'context' => $context->value,
                'document_type' => $result->documentType,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ],
            planId: $row->plan_id !== null ? (int) $row->plan_id : null,
            shopId: $shopId,
        );

        return $row;
    }

    /**
     * Open the `pending` row, or reuse an existing retryable one.
     *
     * A row whose earlier attempt has an UNKNOWN outcome is promoted to `unresolved`
     * here and returned un-retried — that is the wall against a redelivered job
     * minting a second real document. The caller sees a non-retryable row and stops.
     *
     * @param  array<string, mixed>  $linkage
     */
    private function open(
        int $shopId,
        DocumentContext $context,
        string $key,
        IssueDocumentRequest $request,
        array $linkage,
        string $provider,
    ): ?IssuedDocument {
        $attributes = array_merge($linkage, [
            'provider' => $provider,
            'context' => $context->value,
            'idempotency_key' => $key,
            'amount' => round($request->amount, 2),
            'currency' => strtoupper($request->currency),
        ]);

        try {
            $row = IssuedDocument::acrossAllTenants()->firstOrNew([
                'shop_id' => $shopId,
                'idempotency_key' => $key,
            ]);

            // An attempt was made and we never learned its outcome. Record that
            // plainly and STOP — a document may already exist at the provider.
            if ($row->exists && $row->hasUnknownOutcome()) {
                $row->forceFill([
                    'status' => IssuedDocument::STATUS_UNRESOLVED,
                    'failure_code' => self::ERROR_OUTCOME_UNKNOWN,
                    'failure_message' => 'A previous attempt was interrupted; its outcome is unknown.',
                ])->save();

                Log::warning('invoicing.issue.'.self::ERROR_OUTCOME_UNKNOWN, [
                    'shop_id' => $shopId,
                    'document_id' => $row->getKey(),
                    'key' => $key,
                ]);

                return $row;
            }

            if ($row->exists && ! $row->isRetryable()) {
                return $row;
            }

            $row->forceFill(array_merge($attributes, [
                'shop_id' => $shopId,
                'status' => IssuedDocument::STATUS_PENDING,
                // A fresh attempt on a definitively-failed row starts clean.
                'attempted_at' => null,
            ]))->save();

            return $row;
        } catch (Throwable $e) {
            // A unique-index collision means another worker opened it first. Re-read
            // and let the caller decide — this is the concurrency wall doing its job.
            $existing = IssuedDocument::acrossAllTenants()
                ->where('shop_id', $shopId)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing === null) {
                Log::error('invoicing.issue.open_failed', [
                    'shop_id' => $shopId, 'key' => $key, 'error' => $e->getMessage(),
                ]);
            }

            return $existing;
        }
    }

    /**
     * Win the right to call the provider for this row, atomically.
     *
     * `UPDATE … WHERE attempted_at IS NULL` is decided by the database, so exactly
     * one concurrent worker can succeed. Returns false for the loser, which then
     * makes no HTTP call at all — the difference between one tax document and two.
     */
    private function claimAttempt(int $shopId, IssuedDocument $row): bool
    {
        // One timestamp for both the row and the in-memory model, so they cannot
        // disagree by the microseconds between two now() calls.
        $now = now();

        $claimed = IssuedDocument::acrossAllTenants()
            ->where('shop_id', $shopId)
            ->whereKey($row->getKey())
            ->whereNull('attempted_at')
            ->update(['attempted_at' => $now]);

        if ($claimed !== 1) {
            return false;
        }

        // Mark ONLY this attribute as persisted. A whole-model syncOriginal() would
        // silently discard any other unsaved change a future caller happens to be
        // holding — there is none today, but the narrow call cannot acquire one.
        $row->forceFill(['attempted_at' => $now])->syncOriginalAttribute('attempted_at');

        return true;
    }

    /** An already-ISSUED row for this key, or null (a failed row may be retried). */
    private function findIssued(int $shopId, string $key): ?IssuedDocument
    {
        return IssuedDocument::acrossAllTenants()
            ->where('shop_id', $shopId)
            ->where('idempotency_key', $key)
            ->where('status', IssuedDocument::STATUS_ISSUED)
            ->first();
    }

    /**
     * Ask the CENTRAL DocumentPolicy whether to issue, and whether this document
     * links to a previous one. Routing every path through here is what makes the
     * policy central rather than a thing the charge orchestrator alone happens to
     * consult — a merchant switch honoured on four hooks out of five is not a switch.
     *
     * The `document_settings` bag lives on the plan (the shape ChargeOrchestrator
     * already reads); a plain store order has no plan and so carries none.
     */
    private function decide(
        Shop $shop,
        DocumentContext $context,
        float $amount,
        ?InstallmentPlan $plan,
    ): DocumentDecision {
        return app(DocumentPolicy::class)->decide(new DocumentPolicyInput(
            shop: $shop,
            chargeContext: $context->value,
            planKind: $plan?->plan_kind?->value ?? '',
            amount: $amount,
            // The context already carries finality (FINAL_INSTALLMENT is its own
            // case), so the policy's own normalisation has nothing left to do.
            isFinalPayment: $context === DocumentContext::FINAL_INSTALLMENT,
            merchantSettings: (array) (($plan?->meta ?? [])['document_settings'] ?? []),
        ));
    }

    // === Request building helpers ===

    /**
     * The shop, resolved by EXPLICIT id. Shop is the tenant itself, so it carries no
     * BelongsToShop scope; the id comes from the job payload, never global state.
     */
    private function shop(int $shopId): ?Shop
    {
        $shop = Shop::query()->find($shopId);

        if ($shop === null) {
            Log::warning('invoicing.issue.'.self::ERROR_NO_SHOP, ['shop_id' => $shopId]);
        }

        return $shop;
    }

    private function planFor(PaymentLedger $ledger): ?InstallmentPlan
    {
        if ($ledger->plan_id === null) {
            return null;
        }

        return InstallmentPlan::acrossAllTenants()
            ->where('shop_id', $ledger->shop_id)
            ->whereKey($ledger->plan_id)
            ->first();
    }

    /**
     * The description that appears on the document line. Uses the plan's own product
     * title when we have one (what the customer recognises), else a translated,
     * context-specific label — never a raw internal id on a tax document.
     */
    private function lineDescriptionFor(
        DocumentContext $context,
        PaymentLedger $ledger,
        ?InstallmentPlan $plan,
    ): string {
        $title = $plan?->itemTitle();
        if ($title !== null) {
            return $title;
        }

        return __('invoicing.line.'.$context->value, [
            'reference' => (string) ($plan?->public_id ?? $ledger->idempotency_key),
        ]);
    }

    /** Free-text on the document: the plan reference, so it reconciles to LETS. */
    private function remarksFor(PaymentLedger $ledger, ?InstallmentPlan $plan): ?string
    {
        $reference = trim((string) ($plan?->public_id ?? ''));

        return $reference !== ''
            ? __('invoicing.remarks.plan', ['reference' => $reference])
            : null;
    }

    /**
     * The provider id of the document this one links to.
     *
     * A CREDIT NOTE must reference the exact sale it credits — the document issued
     * for THIS ledger row. Taking "the newest document on the plan" instead would,
     * on a 12-payment plan, credit installment #3 against receipt #12; and if #12 is
     * the smaller of the two, the credit note exceeds the document it credits, which
     * the provider (rightly) will not reconcile.
     *
     * A SALE document links only when the policy says so (`shouldLinkToPreviousDocument`
     * — true for the final installment, which ties the plan's receipts together).
     * Linking every receipt to its predecessor, as an unconditional lookup would,
     * builds a chain the merchant's books never asked for.
     */
    private function linkedDocumentIdFor(
        int $shopId,
        DocumentContext $context,
        DocumentDecision $decision,
        PaymentLedger $ledger,
        ?InstallmentPlan $plan,
    ): ?string {
        if ($context->isCredit()) {
            return $this->documentIdForLedger($shopId, (int) $ledger->getKey());
        }

        if (! $decision->shouldLinkToPreviousDocument || $plan === null) {
            return null;
        }

        return IssuedDocument::acrossAllTenants()
            ->where('shop_id', $shopId)
            ->where('plan_id', $plan->getKey())
            ->where('status', IssuedDocument::STATUS_ISSUED)
            ->latest('id')
            ->first()?->provider_document_id;
    }

    /** The issued document recorded for one specific money movement, or null. */
    private function documentIdForLedger(int $shopId, int $ledgerId): ?string
    {
        return IssuedDocument::acrossAllTenants()
            ->where('shop_id', $shopId)
            ->where('ledger_id', $ledgerId)
            ->where('status', IssuedDocument::STATUS_ISSUED)
            ->latest('id')
            ->first()?->provider_document_id;
    }

    /**
     * Document lines from a reported store order. Falls back to a single line at the
     * order total when the platform sent no usable breakdown, and RECONCILES any
     * rounding/shipping/tax gap with a balancing line — the document must total the
     * money that actually moved (IssueDocumentRequest::totalsMatch()).
     *
     * @param  array<string, mixed>  $order
     * @return list<DocumentLine>
     */
    private function linesFromPlatformOrder(array $order): array
    {
        $total = round((float) $order['total'], 2);
        $lines = [];

        foreach ((array) ($order['lines'] ?? []) as $line) {
            $description = trim((string) ($line['description'] ?? ''));
            $unitPrice = round((float) ($line['unit_price'] ?? 0), 2);

            if ($description === '' || $unitPrice <= 0) {
                continue;
            }

            $lines[] = new DocumentLine(
                description: $description,
                unitPrice: $unitPrice,
                quantity: max(1, (int) ($line['quantity'] ?? 1)),
                catalogNumber: $this->blankToNull((string) ($line['catalog_number'] ?? '')),
            );
        }

        if ($lines === []) {
            return [DocumentLine::single(
                __('invoicing.line.platform_order', [
                    'reference' => (string) ($order['order_number'] ?? $order['order_id']),
                ]),
                $total,
            )];
        }

        // Shipping, fees and tax rounding live outside the item lines. Balance the
        // document to the real total instead of shipping a mismatched one.
        $sum = round(array_sum(array_map(static fn (DocumentLine $l): float => $l->total(), $lines)), 2);
        $gap = round($total - $sum, 2);

        if (abs($gap) >= 0.01) {
            $lines[] = DocumentLine::single(__('invoicing.line.adjustment'), $gap);
        }

        return $lines;
    }

    /** @param array<string, mixed> $order */
    private function orderRemarks(array $order): string
    {
        return __('invoicing.remarks.order', [
            'reference' => (string) ($order['order_number'] ?? $order['order_id']),
        ]);
    }

    /**
     * A request that could not even be BUILT (a malformed payload, a missing
     * relation). Recorded as a failed Timeline event so the gap is explainable —
     * but never rethrown, because this hangs off the money path.
     */
    private function recordBuildFailure(int $shopId, DocumentContext $context, Throwable $e): ?IssuedDocument
    {
        Log::error('invoicing.issue.'.self::ERROR_BUILD_FAILED, [
            'shop_id' => $shopId,
            'context' => $context->value,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        Timeline::record(
            kind: Timeline::KIND_DOCUMENT_FAILED,
            details: ['context' => $context->value, 'error_code' => self::ERROR_BUILD_FAILED],
            shopId: $shopId,
        );

        return null;
    }

    private function blankToNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
