<?php

namespace App\Domain\Upsell\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The product offer a flow presents, with optional discount + customer-facing
 * copy. The discounted price is the single source of money truth for the upsell
 * charge — computed HERE, never trusted from the client. Tenant-scoped.
 */
class UpsellFlowOffer extends Model
{
    use BelongsToShop;

    // === CONSTANTS — discount taxonomy ===
    protected $table = 'upsell_flow_offers';

    public const DISCOUNT_NONE = 'none';
    public const DISCOUNT_PERCENT = 'percent';
    public const DISCOUNT_FIXED = 'fixed';

    // === CONSTANTS — "Configure cross-sell" drawer config (UI only; charge
    // engine untouched). Each maps a drawer radio/select to a stored value. ===
    public const PRODUCT_SMART = 'smart_select';
    public const PRODUCT_SPECIFIC = 'specific';
    public const PRODUCT_MODES = [self::PRODUCT_SMART, self::PRODUCT_SPECIFIC];

    public const VARIANT_CUSTOMER = 'customer';
    public const VARIANT_MERCHANT = 'merchant';
    public const VARIANT_MODES = [self::VARIANT_CUSTOMER, self::VARIANT_MERCHANT];

    public const PURCHASE_ONE_TIME = 'one_time';
    public const PURCHASE_SUBSCRIPTION = 'subscription';
    public const PURCHASE_SUBSCRIPTION_ONLY = 'subscription_only';
    public const PURCHASE_OPTIONS = [
        self::PURCHASE_ONE_TIME,
        self::PURCHASE_SUBSCRIPTION,
        self::PURCHASE_SUBSCRIPTION_ONLY,
    ];

    public const SHIPPING_FREE = 'free';
    public const SHIPPING_CHARGE = 'charge';
    public const SHIPPING_MODES = [self::SHIPPING_FREE, self::SHIPPING_CHARGE];

    protected $guarded = ['shop_id'];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'position' => 'integer',
            'apply_discount_on_top' => 'boolean',
            'show_timer' => 'boolean',
            'timer_minutes' => 'integer',
        ];
    }

    /**
     * The numeric Shopify product id parsed from the offer_product_gid
     * (gid://shopify/Product/123 → "123"). Display only — the drawer shows it as
     * "Product ID: {id}" exactly like the Recharge reference.
     */
    public function productNumericId(): string
    {
        if (preg_match('/(\d+)$/', (string) $this->offer_product_gid, $m) === 1) {
            return $m[1];
        }

        return (string) $this->offer_product_gid;
    }

    /** Percent discount value for the drawer's "%" number input (0 = no discount). */
    public function percentDiscountValue(): int
    {
        if ($this->discount_type !== self::DISCOUNT_PERCENT) {
            return 0;
        }

        return (int) round((float) $this->discount_value);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(UpsellFlow::class, 'flow_id');
    }

    /**
     * The price the customer is charged on the saved token. Server-computed from
     * base_price + discount; never read from the request. Floored at 0 and
     * rounded to 2dp (money law).
     */
    public function discountedPrice(): float
    {
        $base = round((float) $this->base_price, 2);

        $price = match ($this->discount_type) {
            self::DISCOUNT_PERCENT => $base * (1 - min(max((float) $this->discount_value, 0), 100) / 100),
            self::DISCOUNT_FIXED => $base - (float) $this->discount_value,
            default => $base,
        };

        return round(max($price, 0), 2);
    }
}
