<?php

namespace App\Domain\Upsell\Models;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Models\Concerns\BelongsToShop;
use App\Modules\PayPlusShopifyInstallments\Concerns\HasGuardedStatus;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A post-purchase upsell flow: triggers (when) + offers (what) + branches
 * (next). Only ACTIVE flows are evaluated, lowest priority first. Tenant-scoped
 * (shop_id + BelongsToShop); status guarded by the canonical flow machine.
 */
class UpsellFlow extends Model
{
    use BelongsToShop;
    use HasGuardedStatus;

    // === CONSTANTS ===
    protected $table = 'upsell_flows';

    /** shop_id auto-stamped; status moves only through the guarded machine. */
    protected $guarded = ['shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => UpsellFlowStatus::class,
            'priority' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === UpsellFlowStatus::ACTIVE;
    }

    // === Relations ===

    public function triggers(): HasMany
    {
        return $this->hasMany(UpsellFlowTrigger::class, 'flow_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(UpsellFlowOffer::class, 'flow_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(UpsellFlowBranch::class, 'flow_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(UpsellOfferEvent::class, 'flow_id');
    }

    /** The first offer to present (lowest position, ties by id). */
    public function firstOffer(): ?UpsellFlowOffer
    {
        return $this->offers()
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    // === HasGuardedStatus contract ===

    protected function statusColumn(): string
    {
        return 'status';
    }

    /** @return array<string, list<BackedEnum>> */
    protected function allowedTransitions(): array
    {
        return UpsellFlowStatus::allowed();
    }

    protected function currentStatus(): BackedEnum
    {
        return $this->status instanceof UpsellFlowStatus
            ? $this->status
            : UpsellFlowStatus::from((string) $this->status);
    }

    protected function timelinePlanId(): ?int
    {
        return null;
    }

    protected function timelinePaymentId(): ?int
    {
        return null;
    }
}
