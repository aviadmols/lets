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

    protected $guarded = ['shop_id'];

    protected function casts(): array
    {
        return [
            'removal_window' => 'integer',
            'enabled' => 'boolean',
            'offer_display_cap' => 'integer',
        ];
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
