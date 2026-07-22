<?php

namespace App\Domain\Invoicing\GreenInvoice;

use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\DocumentLine;
use App\Domain\Invoicing\IssueDocumentRequest;
use App\Domain\Invoicing\IssuedDocumentResult;
use App\Models\MerchantInvoicingSettings;
use App\Models\Shop;

/**
 * Green Invoice ("Morning") implementation of InvoiceProvider: maps the neutral
 * IssueDocumentRequest onto POST /documents and the response back to a neutral
 * result.
 *
 * The DOCUMENT TYPE is resolved HERE, from the merchant's per-context map — not
 * in the billing engine. App\Domain\Billing\DefaultDocumentPolicy keeps the only
 * question it owns ("should a document be issued now, and is it linked?") and
 * still answers in logical terms; turning a context into a Morning numeric code
 * is provider knowledge, so PayPlus document names and Green Invoice codes never
 * leak into each other. This is what lets a second provider be added without
 * touching the orchestrator.
 *
 * Two invariants are enforced BEFORE any HTTP call, because the provider would
 * otherwise reject them opaquely (or worse, accept a wrong document):
 *   - types 320/400/405 require a non-empty payment[] — we only ever send them
 *     for money that actually moved;
 *   - 330 (credit note) requires the document it credits.
 *
 * NEVER throws: every failure comes back as IssuedDocumentResult::failed().
 */
final class GreenInvoiceProvider implements InvoiceProvider
{
    // === CONSTANTS ===
    public const NAME = Shop::INVOICING_PROVIDER_GREEN_INVOICE;

    /** Green Invoice links a credit note to its original by this linkType. */
    private const LINK_TYPE_LINKED = 'linked';

    /** Failure codes this provider originates (transport codes come from the client). */
    private const ERROR_TOTALS_MISMATCH = 'totals_mismatch';
    private const ERROR_MISSING_LINK = 'missing_linked_document';
    private const ERROR_UNPAID_PAYMENT_TYPE = 'unpaid_requires_payment';
    private const ERROR_NO_RESPONSE = 'no_response';

    public function __construct(
        private readonly GreenInvoiceClient $client,
        private readonly MerchantInvoicingSettings $settings,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /** Non-issuing credential probe: obtains a token and nothing else. */
    public function testConnection(): array
    {
        $token = $this->client->token(forceRefresh: true);

        return $token !== null
            ? [true, null]
            : [false, $this->client->lastReason ?? GreenInvoiceClient::REASON_TRANSPORT];
    }

    public function issue(IssueDocumentRequest $request): IssuedDocumentResult
    {
        $type = $this->settings->documentTypeFor($request->context);

        // A document whose lines do not sum to the money that moved is an
        // accounting error, not a display bug. Refuse before it exists.
        if (! $request->totalsMatch()) {
            return IssuedDocumentResult::failed(
                self::ERROR_TOTALS_MISMATCH,
                sprintf(
                    'Document lines total %s but the recorded amount is %s.',
                    number_format($request->lineTotal(), 2, '.', ''),
                    number_format($request->amount, 2, '.', ''),
                ),
                (string) $type->value,
            );
        }

        // A credit note that does not say what it credits is unusable paperwork.
        if ($type->requiresLinkedDocument() && ($request->linkedDocumentId ?? '') === '') {
            return IssuedDocumentResult::failed(
                self::ERROR_MISSING_LINK,
                'A credit note requires the document it credits.',
                (string) $type->value,
            );
        }

        // 320/400/405 assert money changed hands — never issue one for unpaid money.
        if ($type->requiresPayment() && ! $request->isPaid) {
            return IssuedDocumentResult::failed(
                self::ERROR_UNPAID_PAYMENT_TYPE,
                'This document type requires a payment, but the amount is not recorded as paid.',
                (string) $type->value,
            );
        }

        $body = $this->client->createDocument($this->payload($request, $type));

        if ($body === null) {
            return IssuedDocumentResult::failed(
                $this->client->lastReason ?? self::ERROR_NO_RESPONSE,
                (string) ($this->client->lastMessage ?? 'The invoicing provider did not answer.'),
                (string) $type->value,
                $this->client->lastResponse,
            );
        }

        $documentId = (string) ($body['id'] ?? '');
        if ($documentId === '') {
            return IssuedDocumentResult::failed(
                self::ERROR_NO_RESPONSE,
                'The provider accepted the request but returned no document id.',
                (string) $type->value,
                $body,
            );
        }

        return IssuedDocumentResult::issued(
            documentId: $documentId,
            documentNumber: $this->stringOrNull($body['number'] ?? null),
            documentUrl: $this->documentUrlFrom($body),
            documentType: (string) $type->value,
            raw: $body,
        );
    }

    // === Payload mapping ===

    /**
     * @return array<string, mixed>
     */
    private function payload(IssueDocumentRequest $request, GreenInvoiceDocumentType $type): array
    {
        $payload = [
            'type' => $type->value,
            'lang' => $this->settings->documentLanguage(),
            'currency' => strtoupper($request->currency),
            'vatType' => $this->settings->vatType(),
            'rounding' => $this->settings->rounding(),
            'client' => $this->client($request),
            'income' => array_map(
                fn (DocumentLine $line): array => $this->incomeRow($line, $request),
                $request->lines,
            ),
        ];

        if ($request->remarks !== null && trim($request->remarks) !== '') {
            $payload['remarks'] = trim($request->remarks);
        }

        if ($type->requiresPayment()) {
            $payload['payment'] = [$this->paymentRow($request)];
        }

        if ($request->linkedDocumentId !== null && $request->linkedDocumentId !== '') {
            $payload['linkType'] = self::LINK_TYPE_LINKED;
            $payload['linkedDocumentIds'] = [$request->linkedDocumentId];
        }

        return $payload;
    }

    /**
     * The `client` block. `add` asks Green Invoice to create/update the customer in
     * the merchant's own client book, so their CRM stays populated; `emails` is only
     * populated when the merchant opted into provider-side delivery AND the address
     * is real — an invalid address would make the provider reject the whole document.
     *
     * @return array<string, mixed>
     */
    private function client(IssueDocumentRequest $request): array
    {
        $customer = $request->customer;

        $block = array_filter([
            'name' => $customer->name,
            'phone' => $customer->phone,
            'taxId' => $customer->taxId,
            'address' => $customer->address,
            'city' => $customer->city,
        ], static fn ($v): bool => $v !== null && $v !== '');

        $block['add'] = true;

        if ($request->sendEmail && $customer->isEmailable()) {
            $block['emails'] = [$customer->email];
        }

        return $block;
    }

    /**
     * One `income[]` row. `price` is the UNIT price — Green Invoice multiplies by
     * quantity itself, so sending a pre-multiplied line total here would inflate the
     * document by the quantity factor (see DocumentLine::total()).
     *
     * @return array<string, mixed>
     */
    private function incomeRow(DocumentLine $line, IssueDocumentRequest $request): array
    {
        return array_filter([
            'catalogNum' => $line->catalogNumber,
            'description' => $line->description,
            'quantity' => max(1, $line->quantity),
            'price' => round($line->unitPrice, 2),
            'currency' => strtoupper($request->currency),
            'vatType' => $line->vatType ?? $this->settings->vatType(),
        ], static fn ($v): bool => $v !== null && $v !== '');
    }

    /**
     * The single `payment[]` row. `date` is today: the document records money that
     * has already moved by the time we are called (the ledger row is `succeeded`
     * before any issuing hook fires).
     *
     * @return array<string, mixed>
     */
    private function paymentRow(IssueDocumentRequest $request): array
    {
        $type = $request->paymentGateway === null
            ? GreenInvoicePaymentType::CREDIT_CARD          // LETS money is always PayPlus card clearing
            : GreenInvoicePaymentType::fromWooGateway($request->paymentGateway);

        return array_filter([
            'date' => now()->toDateString(),
            'type' => $type->value,
            'price' => round($request->amount, 2),
            'currency' => strtoupper($request->currency),
            'cardNum' => $type === GreenInvoicePaymentType::CREDIT_CARD ? $request->cardLast4 : null,
        ], static fn ($v): bool => $v !== null && $v !== '');
    }

    /**
     * The merchant-facing document URL. Green Invoice answers a `url` object keyed
     * by language ({origin, he, en}); prefer the merchant's document language, then
     * `origin`, then any URL present — a stored link that 404s is worse than none.
     *
     * @param  array<string, mixed>  $body
     */
    private function documentUrlFrom(array $body): ?string
    {
        $url = $body['url'] ?? null;

        if (is_string($url)) {
            return $url !== '' ? $url : null;
        }

        if (! is_array($url)) {
            return $this->stringOrNull($body['docUrl'] ?? null);
        }

        $preferred = $this->settings->documentLanguage();

        foreach ([$preferred, 'origin', 'he', 'en'] as $key) {
            $candidate = $this->stringOrNull($url[$key] ?? null);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
