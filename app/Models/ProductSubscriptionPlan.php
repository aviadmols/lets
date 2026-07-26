<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Modules\PayPlusShopifyInstallments\Concerns\HasGuardedStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-product/variant subscription plan TEMPLATE — the merchant's reusable
 * config a customer plan inherits from at order.paid time (see
 * ProductPlanTemplateResolver). NOT a per-customer instance (that's
 * InstallmentPlan). Tenant-scoped (shop_id + BelongsToShop). status is a guarded
 * state machine (HasGuardedStatus + PlanTemplateStatus): draft <-> active only,
 * and every accepted move writes a Timeline event.
 *
 * Pattern source: app/Models/InstallmentPlan.php (the guarded-status contract).
 */
class ProductSubscriptionPlan extends Model
{
    use BelongsToShop;
    use HasGuardedStatus;

    // === CONSTANTS — plan-type taxonomy (allow-lists guard sanitization) ===
    protected $table = 'product_subscription_plans';

    public const TYPE_ONE_TIME = 'one_time';
    public const TYPE_SUBSCRIPTION = 'subscription';
    public const PLAN_TYPES = [self::TYPE_ONE_TIME, self::TYPE_SUBSCRIPTION];

    public const DISCOUNT_NONE = 'none';
    public const DISCOUNT_PERCENT = 'percent';
    public const DISCOUNT_FIXED = 'fixed';
    public const DISCOUNT_TYPES = [self::DISCOUNT_NONE, self::DISCOUNT_PERCENT, self::DISCOUNT_FIXED];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';

    /**
     * Which engine bills THIS template. null/'' = follow the shop's
     * subscription_rail (the normal case); the explicit values let one product
     * opt into the other engine without moving the whole store.
     */
    public const RAIL_INHERIT = '';
    public const RAIL_PAYPLUS = Shop::RAIL_PAYPLUS;
    public const RAIL_SHOPIFY_PAYMENTS = Shop::RAIL_SHOPIFY_PAYMENTS;
    public const BILLING_RAILS = [self::RAIL_PAYPLUS, self::RAIL_SHOPIFY_PAYMENTS];

    // === CONSTANTS — channels this template is offered through ===
    public const CHANNEL_STOREFRONT_WIDGET = 'storefront_widget';
    public const CHANNEL_CUSTOMER_PORTAL = 'customer_portal';
    public const CHANNEL_MERCHANT_PORTAL = 'merchant_portal';
    public const CHANNEL_API = 'api';
    public const CHANNELS = [
        self::CHANNEL_STOREFRONT_WIDGET,
        self::CHANNEL_CUSTOMER_PORTAL,
        self::CHANNEL_MERCHANT_PORTAL,
        self::CHANNEL_API,
    ];

    /**
     * shop_id (auto-stamped by BelongsToShop) AND status (the guarded state
     * machine is the ONLY legal mutation path) are guarded so a raw create()/
     * update() cannot set them. Set the INITIAL status via forceFill at creation.
     */
    protected $guarded = ['shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'plan_kind' => PlanKind::class,
            'status' => PlanTemplateStatus::class,
            'billing_frequency' => BillingFrequency::class,
            'discount_value' => 'decimal:2',
            'interval_count' => 'integer',
            'charge_day_of_month' => 'integer',
            'expire_after_charges' => 'integer',
            'position' => 'integer',
            'channels' => 'array',
            'shopify_synced_at' => 'datetime',
        ];
    }

    // === Relations ===

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Null = the template applies to all variants of the product. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    // === Money helper (server-computed; never trust the client) ===

    /**
     * The price a customer pays per cycle for this template, computed from a base
     * price + the template discount. Floored at 0, rounded to 2dp (money law).
     * Mirrors UpsellFlowOffer::discountedPrice — the discount math is identical.
     */
    public function discountedPrice(float $basePrice): float
    {
        $base = round($basePrice, 2);

        $price = match ($this->discount_type) {
            self::DISCOUNT_PERCENT => $base * (1 - min(max((float) $this->discount_value, 0), 100) / 100),
            self::DISCOUNT_FIXED => $base - (float) $this->discount_value,
            default => $base,
        };

        return round(max($price, 0), 2);
    }

    public function isSubscription(): bool
    {
        return $this->plan_type === self::TYPE_SUBSCRIPTION;
    }

    // === Billing rail (which engine bills this template) ===

    /**
     * The rail this template ACTUALLY bills on: its own override when set to a
     * known value, otherwise the shop's choice. One-time templates have no rail
     * (nothing recurs) — they always charge through PayPlus like any order.
     */
    public function effectiveRail(?Shop $shop = null): string
    {
        $own = (string) ($this->billing_rail ?? '');
        if (in_array($own, self::BILLING_RAILS, true)) {
            return $own;
        }

        $shop ??= $this->shop;

        return $shop instanceof Shop ? $shop->subscriptionRail() : Shop::RAIL_PAYPLUS;
    }

    public function usesShopifyPayments(?Shop $shop = null): bool
    {
        return $this->isSubscription()
            && $this->effectiveRail($shop) === Shop::RAIL_SHOPIFY_PAYMENTS;
    }

    /**
     * Is this template LIVE at Shopify as a selling plan? Configuration alone
     * does not make a product subscribable at checkout — only the selling plan
     * does, and without it no contract (and so no billing) can ever exist.
     */
    public function isPublishedToShopify(): bool
    {
        return (string) ($this->shopify_selling_plan_gid ?? '') !== '';
    }

    // === HasGuardedStatus contract ===

    protected function statusColumn(): string
    {
        return 'status';
    }

    /** @return array<string, list<BackedEnum>> */
    protected function allowedTransitions(): array
    {
        return PlanTemplateStatus::allowed();
    }

    protected function currentStatus(): BackedEnum
    {
        return $this->status instanceof PlanTemplateStatus
            ? $this->status
            : PlanTemplateStatus::from((string) $this->status);
    }

    /** Templates are not customer plans — no plan/payment id for the Timeline row. */
    protected function timelinePlanId(): ?int
    {
        return null;
    }

    protected function timelinePaymentId(): ?int
    {
        return null;
    }
}
