<?php

namespace App\Domain\Upsell\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * One order waiting out its add-on window.
 *
 * The row IS the hold: a scanner reads it, so a shortened window takes effect on
 * the next pass and a lost queue message costs a late release rather than an
 * order parked forever. `hold_refs` records what the platform needs back in
 * order to undo the hold, captured at hold time so a release never depends on
 * re-deriving the same set from an order that may since have changed.
 */
class UpsellOrderHold extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'upsell_order_holds';

    public const STATUS_HELD = 'held';
    public const STATUS_RELEASED = 'released';
    /** We decided not to hold (or not to release) — skip_reason says why. */
    public const STATUS_SKIPPED = 'skipped';

    public const PLATFORM_SHOPIFY = 'shopify';
    public const PLATFORM_WOOCOMMERCE = 'woocommerce';

    /** Why an order was not held. Named, because "nothing happened" is unhelpful. */
    public const SKIP_INSTALLMENTS_HOLD = 'installments_hold';
    public const SKIP_GIFT = 'gift_order';
    public const SKIP_RECURRING = 'recurring_cycle';
    public const SKIP_UPSELL_CHILD = 'upsell_child';
    public const SKIP_NO_FULFILLMENT = 'no_fulfillment_order';
    public const SKIP_PLATFORM_REFUSED = 'platform_refused';

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'hold_refs' => 'array',
            'added_items' => 'array',
            'release_at' => 'datetime',
            'released_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function isHeld(): bool
    {
        return $this->status === self::STATUS_HELD;
    }

    /** Did the shopper actually add anything while the window was open? */
    public function hasAdditions(): bool
    {
        return is_array($this->added_items) && $this->added_items !== [];
    }
}
