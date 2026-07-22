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
     * meta key holding a ONE-TIME "next order" override (W25): the merchant-edited contents of the
     * next recurring cycle only. Shape: {line_items:[{product_id,name,quantity,unit_price}],
     * amount, currency, set_by, set_at}. Consumed by the next charge (amountFor + onRecurring),
     * then cleared on success so the following cycle reverts to the plan's normal contents.
     */
    public const META_NEXT_ORDER = 'next_order';

    /**
     * meta key holding the CATALOG TITLE of the item this plan sells, captured at
     * checkout. Read by the invoicing module so an accounting document names the
     * product the customer recognises rather than an internal plan id — a tax
     * document line is customer-facing paperwork, not an audit trail.
     */
    public const META_ITEM_TITLE = 'item_title';

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

    /**
     * The platform-neutral external CUSTOMER id — the sibling of externalOrderId(). WooCommerce
     * fills external_customer_id; the legacy Shopify column is the fallback.
     */
    public function externalCustomerId(): ?string
    {
        return $this->external_customer_id ?: $this->shopify_customer_id;
    }

    /** The platform-neutral external PRODUCT id (WooCommerce external_product_id, Shopify fallback). */
    public function externalProductId(): ?string
    {
        return $this->external_product_id ?: $this->shopify_product_id;
    }

    /**
     * The ONE-TIME next-order override (W25), or null when the next cycle uses the plan's normal
     * contents. Only returned when it actually carries line items (a malformed/empty bag is ignored).
     *
     * @return array<string, mixed>|null
     */
    public function nextOrderOverride(): ?array
    {
        $override = $this->meta[self::META_NEXT_ORDER] ?? null;

        return is_array($override) && ! empty($override['line_items']) ? $override : null;
    }

    /** Drop the one-time next-order override (after it has been consumed by a charge). */
    public function clearNextOrderOverride(): void
    {
        $meta = (array) ($this->meta ?? []);
        if (array_key_exists(self::META_NEXT_ORDER, $meta)) {
            unset($meta[self::META_NEXT_ORDER]);
            $this->forceFill(['meta' => $meta])->save();
        }
    }

    /**
     * The catalog title of the item this plan sells, captured at checkout, or null
     * when the plan predates it / the path never sent one. Callers fall back to
     * their own translated label — never to a raw id on customer-facing paperwork.
     */
    public function itemTitle(): ?string
    {
        $title = trim((string) (($this->meta ?? [])[self::META_ITEM_TITLE] ?? ''));

        return $title !== '' ? $title : null;
    }

    /**
     * A HUMAN label for the plan's customer. There is no Customer model/table (customers are
     * derived from plans), so the plan row itself is the source of truth: customer_name is captured
     * at checkout on BOTH WooCommerce paths and by the Shopify order sync.
     *
     * Precedence mirrors PortalSignedUrlService's identity chain (name → email → external id):
     * a WooCommerce plan often has NO external_customer_id at all (the subscribe path never sends
     * one, and a guest checkout has none), which is exactly why the list — keyed on
     * shopify_customer_id — showed an empty "Customer" cell while the name sat in the same row.
     * Whitespace-only values are treated as absent (mirrors Mail\TemplateRenderer::nonEmpty).
     */
    public function customerLabel(): string
    {
        foreach ([$this->customer_name, $this->customer_email, $this->externalCustomerId()] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return __('common.none');
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
