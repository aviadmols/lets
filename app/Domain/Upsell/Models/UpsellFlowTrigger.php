<?php

namespace App\Domain\Upsell\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One trigger rule for a flow. The resolver matches these against the source
 * purchase (purchased product gids + order subtotal). OR semantics across a
 * flow's triggers. Tenant-scoped.
 */
class UpsellFlowTrigger extends Model
{
    use BelongsToShop;

    // === CONSTANTS — match_type taxonomy ===
    protected $table = 'upsell_flow_triggers';

    public const MATCH_ANY_PRODUCT = 'any_product';
    public const MATCH_SPECIFIC_PRODUCT = 'specific_product';
    public const MATCH_COLLECTION = 'collection';
    public const MATCH_TAG = 'tag';
    public const MATCH_MIN_ORDER_VALUE = 'min_order_value';

    protected $guarded = ['shop_id'];

    protected function casts(): array
    {
        return [
            'min_order_value' => 'decimal:2',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(UpsellFlow::class, 'flow_id');
    }
}
