<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per accounting document we asked a provider to issue — the invoicing
 * analogue of PaymentLedger, and governed by the same law: NO document request
 * without a row here FIRST. The row is written `pending` before the provider HTTP
 * call, so a process death mid-issue leaves a reconcilable trace rather than a
 * silent duplicate; the result then transitions that exact row.
 *
 * The UNIQUE (shop_id, idempotency_key) index is the double-issue wall. The key
 * is derived deterministically from the money event (see DocumentIssuer), so a
 * replayed webhook, a re-queued job, or a WooCommerce order flapping
 * processing → completed can never mint a second document for the same money.
 *
 * Tenant-scoped via BelongsToShop. `status` is guarded because it has exactly TWO
 * legitimate writers and no others: DocumentIssuer (the automatic path) and
 * DocumentReconciliationService (the deliberate human path). A raw create/update
 * must never move it.
 *
 * `source_payload` holds the storefront's original report for a plain store order
 * — the only way such a document can be re-issued faithfully, since it has no
 * ledger row or plan to rebuild from. It carries customer identity and is
 * therefore scrubbed by both redaction jobs.
 */
class IssuedDocument extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'issued_documents';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_FAILED = 'failed';
    /**
     * An attempt was made but its outcome is UNKNOWN — the worker died between the
     * provider call and our write, or the provider accepted without returning an id.
     * A document may or may not exist at the provider, so this is terminal for
     * AUTOMATION: nothing re-posts it. A human (or a provider-side search) reconciles.
     */
    public const STATUS_UNRESOLVED = 'unresolved';

    /** The idempotency-key prefix every invoicing key carries. */
    public const KEY_PREFIX = 'doc:';

    /**
     * Failure codes that PROVE the provider created nothing — the provider either
     * rejected the request outright, or we refused it before any HTTP. Only these may
     * be retried automatically. A transport error or an id-less 2xx is NOT here: the
     * request may well have created a document, and re-posting would mint a second.
     */
    public const RETRYABLE_FAILURES = [
        'totals_mismatch',
        'missing_linked_document',
        'unpaid_requires_payment',
        'rejected',
        'unauthorized',
        'no_credentials',
        // The TOKEN request failed in transport, so the documents endpoint was never
        // reached. Distinct from a transport failure on the document call itself,
        // which is ambiguous and stays non-retryable.
        'token_transport',
    ];

    /**
     * Hardened mass-assignment: shop_id (auto-stamped by BelongsToShop) and status
     * (advanced only through DocumentIssuer) cannot be set by a raw create/update.
     */
    protected $guarded = ['shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'raw_response_masked' => 'array',
            'source_payload' => 'array',
            'issued_at' => 'datetime',
            'attempted_at' => 'datetime',
        ];
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    /**
     * May an issue attempt be made against this row?
     *
     * A `pending` row that already carries an `attempted_at` is NOT retryable: an
     * HTTP call was made and its outcome is unknown (the worker died before we could
     * record it), so a document may already exist at the provider. A `failed` row is
     * retryable only when its failure code proves nothing was created.
     *
     * The asymmetry is deliberate. A missing document is a button-click for the
     * merchant to fix; a duplicate tax document is a VAT correction with the
     * authority.
     */
    public function isRetryable(): bool
    {
        return match ($this->status) {
            self::STATUS_PENDING => $this->attempted_at === null,
            self::STATUS_FAILED => in_array((string) $this->failure_code, self::RETRYABLE_FAILURES, true),
            default => false,
        };
    }

    /** Was an attempt made whose outcome we never learned? */
    public function hasUnknownOutcome(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->attempted_at !== null;
    }

    // === Relations ===

    /** The money movement this document records. NULL for a plain store order. */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(PaymentLedger::class, 'ledger_id');
    }

    /** The plan this document belongs to. NULL for upsells and plain store orders. */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'plan_id');
    }

    // === Presentation ===

    /** The merchant-facing document label, e.g. "320 · 60001". Never null. */
    public function label(): string
    {
        $number = trim((string) ($this->document_number ?? ''));
        $type = trim((string) ($this->document_type ?? ''));

        return trim($type.($number !== '' ? ' · '.$number : '')) ?: __('common.none');
    }
}
