<?php

namespace App\Modules\PayPlusShopifyInstallments\Services\PayPlus;

/**
 * Normalized result of a PayPlus REST call. Ported from the reference engine's
 * defensive parser: PayPlus returns the transaction uid in TWO shapes
 * (data.transaction.uid nested, OR a flat data.transaction_uid), and the
 * status/code lives under `results.status` / `results.code` /
 * `results.description`. This DTO collapses both shapes to one stable surface.
 *
 * The raw response is exposed UN-masked here; the ChargeOrchestrator masks it
 * before it ever touches the ledger (raw_response_masked).
 */
final class GatewayResult
{
    // === CONSTANTS ===
    /** PayPlus signals success with results.status === 'success'. */
    public const STATUS_SUCCESS = 'success';

    public function __construct(
        public readonly bool $success,
        public readonly ?string $transactionUid = null,
        public readonly ?string $approvalNumber = null,
        public readonly ?string $documentUid = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = [],
    ) {}

    /**
     * Build from a decoded PayPlus JSON body. Handles both response shapes.
     */
    public static function fromResponse(array $body): self
    {
        $results = $body['results'] ?? [];
        $data = $body['data'] ?? [];

        $status = strtolower((string) ($results['status'] ?? ''));
        $success = $status === self::STATUS_SUCCESS;

        // transaction uid: nested OR flat. Never trust an empty string.
        $uid = $data['transaction']['uid']
            ?? $data['transaction_uid']
            ?? $data['uid']
            ?? null;
        $uid = ($uid === '' ? null : $uid);

        $approval = $data['transaction']['approval_number']
            ?? $data['approval_number']
            ?? null;

        $documentUid = $data['document_uid']
            ?? $data['invoice_uid']
            ?? ($data['document']['uid'] ?? null);
        $documentUid = ($documentUid === '' ? null : $documentUid);

        return new self(
            success: $success,
            transactionUid: $uid,
            approvalNumber: $approval !== '' ? $approval : null,
            documentUid: $documentUid,
            errorCode: $success ? null : (string) ($results['code'] ?? 'unknown'),
            errorMessage: $success ? null : (string) ($results['description'] ?? 'Unknown PayPlus error'),
            raw: $body,
        );
    }

    /**
     * The card PayPlus actually charged, as it describes it right now.
     *
     * Every charge response carries `data.card_information` — the four digits,
     * the brand, and the CURRENT expiry. That last one matters more than it
     * looks: the expiry we store is written once, when the card is vaulted (or
     * copied from a migration file), and never again — while the token keeps
     * working through a bank's renewal, which issues the same card with a new
     * date. So our label goes stale while the card is perfectly chargeable, and
     * this is where the truth comes back to us.
     *
     * Shapes vary by endpoint, so both the nested and the flat forms are read.
     * Returns only the keys that were actually present — an absent field must
     * not overwrite a good stored value with a null.
     *
     * @return array{exp_month?: int, exp_year?: int, card_last_four?: string, card_brand?: string}
     */
    public function cardInformation(): array
    {
        $card = $this->raw['data']['card_information']
            ?? $this->raw['data']['data']['card_information']
            ?? $this->raw['card_information']
            ?? null;

        if (! is_array($card)) {
            return [];
        }

        $out = [];

        $month = (int) ($card['expiry_month'] ?? 0);
        if ($month >= 1 && $month <= 12) {
            $out['exp_month'] = $month;
        }

        // PayPlus sends two-digit years ("29") as often as four. Both mean this
        // century; a bare "29" written straight to the column would read as the
        // year 29 and make every card look expired.
        $year = (int) ($card['expiry_year'] ?? 0);
        if ($year > 0) {
            $out['exp_year'] = $year < 100 ? 2000 + $year : $year;
        }

        $four = trim((string) ($card['four_digits'] ?? ''));
        if ($four !== '') {
            $out['card_last_four'] = $four;
        }

        $brand = trim((string) ($card['brand_name'] ?? $card['brand'] ?? ''));
        if ($brand !== '') {
            $out['card_brand'] = $brand;
        }

        return $out;
    }

    /** Build a transport-level failure (HTTP error, timeout, malformed body). */
    public static function transportFailure(string $code, string $message, array $raw = []): self
    {
        return new self(
            success: false,
            errorCode: $code,
            errorMessage: $message,
            raw: $raw,
        );
    }
}
