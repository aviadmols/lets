<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Modules\PayPlusShopifyInstallments\Concerns\HasGuardedStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription plan — installments-until-paid OR open-ended recurring
 * (plan_kind discriminator). Ported + multi-tenant-refactored from the
 * reference engine's InstallmentPlan. Tenant-scoped (shop_id + BelongsToShop)
 * and guarded by the canonical state machine (HasGuardedStatus).
 *
 * Source: app/Modules/PayPlusShopifyInstallments/Models/InstallmentPlan.php
 */
class InstallmentPlan extends Model
{
    use BelongsToShop;
    use HasGuardedStatus;

    // === CONSTANTS ===
    protected $table = 'installment_plans';

    /** Completion threshold for installments (config-overridable). */
    public const REMAINING_EPSILON = 0.005;

    /**
     * Hardened mass-assignment: shop_id (auto-stamped by BelongsToShop) and
     * status (the state machine is the ONLY legal mutation path) are guarded so a
     * raw Model::create()/update() cannot set them and bypass tenancy or the
     * guarded transition. Set the INITIAL status via forceFill at creation.
     */
    protected $guarded = ['shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'plan_kind' => PlanKind::class,
            'status' => PlanStatus::class,
            'billing_frequency' => BillingFrequency::class,
            'total_amount' => 'decimal:2',
            'total_charged' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'interval_count' => 'integer',
            'requires_manual_payment' => 'boolean',
            'next_charge_at' => 'datetime',
            'last_charge_attempt_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    // === Money helpers (installments) ===

    public function remainingAmount(): float
    {
        return round((float) $this->total_amount - (float) $this->total_charged, 2);
    }

    public function isFullyPaid(): bool
    {
        return $this->plan_kind === PlanKind::INSTALLMENTS
            && $this->remainingAmount() <= self::REMAINING_EPSILON;
    }

    public function isRecurring(): bool
    {
        return $this->plan_kind === PlanKind::RECURRING;
    }

    /**
     * The platform-neutral external order id: the WooCommerce/Shopify external order id
     * (external_order_id) falling back to the legacy shopify_order_id column — so both
     * platforms resolve uniformly without renaming the original Shopify columns (W11).
     */
    public function externalOrderId(): ?string
    {
        return $this->external_order_id ?: $this->shopify_order_id;
    }

    // === Relations ===

    public function payments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class, 'plan_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(InstallmentPaymentMethod::class, 'payment_method_id');
    }

    public function activePaymentMethod(): ?InstallmentPaymentMethod
    {
        return $this->paymentMethod;
    }

    // === HasGuardedStatus contract ===

    protected function statusColumn(): string
    {
        return 'status';
    }

    /** @return array<string, list<BackedEnum>> */
    protected function allowedTransitions(): array
    {
        return PlanStatus::allowed();
    }

    protected function currentStatus(): BackedEnum
    {
        return $this->status instanceof PlanStatus
            ? $this->status
            : PlanStatus::from((string) $this->status);
    }

    protected function timelinePlanId(): ?int
    {
        return $this->getKey();
    }

    protected function timelinePaymentId(): ?int
    {
        return null;
    }
}
