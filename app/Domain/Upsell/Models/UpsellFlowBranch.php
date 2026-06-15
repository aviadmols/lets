<?php

namespace App\Domain\Upsell\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The accept/decline edges out of one offer. Null next-offer = the flow ends on
 * that path. The resolver reads this to route the customer to the next offer
 * after an accept or a decline. Tenant-scoped.
 */
class UpsellFlowBranch extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'upsell_flow_branches';

    protected $guarded = ['shop_id'];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(UpsellFlow::class, 'flow_id');
    }

    public function fromOffer(): BelongsTo
    {
        return $this->belongsTo(UpsellFlowOffer::class, 'from_offer_id');
    }
}
