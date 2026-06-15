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

    protected $guarded = ['shop_id'];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'position' => 'integer',
        ];
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
