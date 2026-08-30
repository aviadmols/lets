<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /** A plain WooCommerce storefront checkout paid on the PayPlus page. */
    public const CONTEXT_GATEWAY = 'gateway';

    /**
     * A one-time product bought from an offer inside the customer's own account
     * area, on the card their subscription already saved.
     *
     * Deliberately NOT `upsell`. Mechanically the two are twins — one click, a
     * saved token, no card re-entry — but `upsell` means the post-purchase flow
     * on the thank-you page, and the merchant's dashboard sums exactly that
     * context into "upsell revenue" (DashboardMetrics::upsellRevenue). Filing
     * account-area add-ons there would report money from a funnel that never ran.
     */
    public const CONTEXT_ACCOUNT_OFFER = 'account_offer';

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
            'refunded_amount' => 'decimal:2',
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

    /**
     * The accounting document issued for this money movement, when the merchant has
     * an invoicing provider connected. NULL for every shop that has not opted in —
     * the invoicing module is additive and never required.
     */
    public function issuedDocument(): HasOne
    {
        return $this->hasOne(IssuedDocument::class, 'ledger_id')
            ->where('status', IssuedDocument::STATUS_ISSUED)
            ->latestOfMany();
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
        // A name captured ON the row wins: a plain store checkout has no plan to
        // borrow one from, and it is also the only identity that is guaranteed to
        // still describe THIS charge if the customer is later renamed.
        $name = trim((string) ($this->customer_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $plan = $this->plan ?: $this->resolveCustomerPlan();
        if ($plan !== null) {
            return $plan->customerLabel();
        }

        $email = trim((string) ($this->customer_email ?? ''));
        if ($email !== '') {
            return $email;
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
