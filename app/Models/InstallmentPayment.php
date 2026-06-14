<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Modules\PayPlusShopifyInstallments\Concerns\HasGuardedStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One charge slot within a plan: a deposit, a scheduled installment by sequence,
 * or one recurring cycle. Ported from the reference engine's InstallmentPayment.
 * Tenant-scoped and guarded. markSucceeded() records the PayPlus uid/approval +
 * masked raw, and never persists an empty-string transaction uid.
 *
 * Source: app/Modules/PayPlusShopifyInstallments/Models/InstallmentPayment.php
 */
class InstallmentPayment extends Model
{
    use BelongsToShop;
    use HasGuardedStatus;

    // === CONSTANTS ===
    protected $table = 'installment_payments';

    /**
     * Hardened mass-assignment: shop_id (auto-stamped) and status (mutated only
     * via the guarded slot state machine) cannot be set by a raw create/update.
     * The slot is born `pending` (the column default); markSucceeded() and the
     * orchestrator drive every later move through transitionTo().
     */
    protected $guarded = ['shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'payment_type' => PaymentType::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'sequence' => 'integer',
            'attempt_count' => 'integer',
            'charged_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'raw_response_masked' => 'array',
        ];
    }

    /**
     * Record a successful PayPlus charge. NEVER persists '' for the uid — the
     * unique index collides on empty string (scar tissue); fall back to null.
     */
    public function markSucceeded(?string $transactionUid, ?string $approvalNumber, array $maskedRaw = []): void
    {
        $this->payplus_transaction_uid = ($transactionUid === '' ? null : $transactionUid);
        $this->approval_number = ($approvalNumber === '' ? null : $approvalNumber);
        $this->raw_response_masked = $maskedRaw;
        $this->charged_at = now();
        $this->save();

        $this->transitionTo(PaymentStatus::SUCCEEDED);
    }

    // === Relations ===

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'plan_id');
    }

    // === HasGuardedStatus contract ===

    protected function statusColumn(): string
    {
        return 'status';
    }

    /** @return array<string, list<BackedEnum>> */
    protected function allowedTransitions(): array
    {
        return PaymentStatus::allowed();
    }

    protected function currentStatus(): BackedEnum
    {
        return $this->status instanceof PaymentStatus
            ? $this->status
            : PaymentStatus::from((string) $this->status);
    }

    protected function timelinePlanId(): ?int
    {
        return $this->plan_id;
    }

    protected function timelinePaymentId(): ?int
    {
        return $this->getKey();
    }
}
