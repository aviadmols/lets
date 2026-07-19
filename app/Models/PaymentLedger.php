<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable row per money movement. Append-only in spirit: a charge is
 * written `pending`, then transitions to a terminal state. No charge happens
 * without a row here. Tenant-scoped via BelongsToShop.
 */
class PaymentLedger extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'payment_ledger';

    public const CONTEXT_DEPOSIT = 'deposit';
    public const CONTEXT_INSTALLMENT = 'installment';
    public const CONTEXT_RECURRING = 'recurring';
    public const CONTEXT_UPSELL = 'upsell';
    public const CONTEXT_RETRY = 'retry';
    public const CONTEXT_MANUAL = 'manual';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_RETRY_SCHEDULED = 'retry_scheduled';

    /**
     * Hardened mass-assignment: shop_id (auto-stamped) and status (advanced only
     * via Ledger::transition, the canonical money machine) cannot be set by a raw
     * create/update. A row is born `pending` (the column default); Ledger::open
     * and Ledger::transition own every status write.
     */
    protected $guarded = ['shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'raw_response_masked' => 'array',
        ];
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    // === Relations ===

    /**
     * The plan this charge belongs to (deposit/installment/recurring). NULL for an
     * upsell charge — an upsell is a charge CONTEXT, not a plan.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'plan_id');
    }

    // === Presentation ===

    /**
     * A HUMAN label for this charge's customer. There is no Customer model — the name is
     * captured on the InstallmentPlan at checkout (customer_name/customer_email). A plan-based
     * charge reads it from the linked plan; a plan-less upsell charge borrows the label from the
     * newest plan that shares this charge's saved-customer identity (customer_id /
     * shopify_customer_id), since the upsell always charges a token vaulted by an earlier plan.
     * Falls back to the raw external id, else common.none — mirrors InstallmentPlan::customerLabel().
     */
    public function customerLabel(): string
    {
        $plan = $this->plan ?: $this->resolveCustomerPlan();
        if ($plan !== null) {
            return $plan->customerLabel();
        }

        $shopifyId = trim((string) ($this->shopify_customer_id ?? ''));

        return $shopifyId !== '' ? $shopifyId : __('common.none');
    }

    /**
     * The newest InstallmentPlan for this charge's customer (this shop only, via the
     * BelongsToShop global scope), matched on shopify_customer_id (string) or the numeric
     * customer_id. Only used for plan-less charges (upsells). customer_id is a BIGINT — never
     * compare it to a non-numeric value (Postgres 22P02), mirroring
     * UpsellChargeService::resolvePaymentMethod's type care.
     */
    private function resolveCustomerPlan(): ?InstallmentPlan
    {
        $shopifyId = trim((string) ($this->shopify_customer_id ?? ''));
        $customerId = $this->customer_id;

        if ($shopifyId === '' && $customerId === null) {
            return null;
        }

        return InstallmentPlan::query()
            ->where(function (Builder $q) use ($shopifyId, $customerId): void {
                if ($shopifyId !== '') {
                    $q->orWhere('shopify_customer_id', $shopifyId)
                        ->orWhere('external_customer_id', $shopifyId);
                }
                if ($customerId !== null) {
                    $q->orWhere('customer_id', $customerId);
                }
            })
            ->latest('id')
            ->first();
    }
}
