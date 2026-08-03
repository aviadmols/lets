<?php

namespace App\Domain\Upsell\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-shop upsell preferences edited on the Post-Purchase Offers → Settings tab
 * (docs/ux/40 Tab 4). UI/merchant-preference storage ONLY — it never gates the
 * charge engine. Tenant-scoped (shop_id + BelongsToShop); exactly one row per
 * shop, lazily created with spec defaults.
 */
class UpsellSetting extends Model
{
    use BelongsToShop;

    // === CONSTANTS — partial-paid handling taxonomy (docs/ux/40, D2) ===
    protected $table = 'upsell_settings';

    /** Leave the upsell on an unpaid parent order untouched (recommended). */
    public const PARTIAL_DO_NOTHING = 'do_nothing';
    /** Remove the upsell line from a not-fully-paid parent order. */
    public const PARTIAL_REMOVE_ITEM = 'remove_item';

    /** Removal-window options (hours) offered when handling = remove_item. */
    public const REMOVAL_WINDOWS = [12, 24, 48, 72];

    /**
     * How long an order may wait while the shopper decides whether to add
     * something. 0 = no hold, and that is the default: holding a paid order
     * costs the merchant a later dispatch and a support question, so it is
     * opt-in and never a side effect of installing the app.
     */
    public const HOLD_WINDOWS = [0, 10, 20, 30, 60, 120];

    /**
     * The ceiling, whatever is stored. A hold is a delay on goods somebody has
     * already paid for; past a couple of hours it stops being an upsell window
     * and starts being a fulfillment problem.
     */
    public const MAX_HOLD_MINUTES = 120;

    protected $guarded = ['shop_id'];

    protected function casts(): array
    {
        return [
            'removal_window' => 'integer',
            'enabled' => 'boolean',
            'offer_display_cap' => 'integer',
            'hold_window_minutes' => 'integer',
            'hold_notify' => 'boolean',
        ];
    }

    /** Clamped: a merchant typo must not park a paid order for a week. */
    public function holdWindowMinutes(): int
    {
        return max(0, min(self::MAX_HOLD_MINUTES, (int) $this->hold_window_minutes));
    }

    /** Does this shop hold orders at all? */
    public function holdEnabled(): bool
    {
        return (bool) $this->enabled && $this->holdWindowMinutes() > 0;
    }

    /** Email the shopper when the window closes on an order they added to? */
    public function holdNotify(): bool
    {
        return (bool) $this->hold_notify;
    }

    /**
     * The settings row for the current tenant, created with explicit spec
     * defaults on first read (so the in-memory model carries the values even
     * before a DB round-trip). Tenant-scoped: shop_id auto-stamped by
     * BelongsToShop.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['shop_id' => Tenant::id()],
            [
                'partial_paid_handling' => self::PARTIAL_REMOVE_ITEM,
                'removal_window' => 24,
                'enabled' => true,
                'offer_display_cap' => 1,
            ],
        );
    }
}
