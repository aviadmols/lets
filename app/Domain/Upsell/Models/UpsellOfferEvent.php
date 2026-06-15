<?php

namespace App\Domain\Upsell\Models;

use App\Domain\Upsell\Enums\OfferEventType;
use App\Models\Concerns\BelongsToShop;
use App\Modules\PayPlusShopifyInstallments\Support\ResponseMasker;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Append-only upsell funnel event. The analytics truth behind every KPI. Like
 * the Timeline, recording NEVER throws into the money path — a failed analytics
 * write is logged and swallowed. Tenant-scoped.
 */
class UpsellOfferEvent extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    public const UPDATED_AT = null; // append-only

    protected $table = 'upsell_offer_events';

    protected $guarded = ['shop_id'];

    protected function casts(): array
    {
        return [
            'event_type' => OfferEventType::class,
            'revenue_amount' => 'decimal:2',
            'context' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(UpsellFlow::class, 'flow_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(UpsellFlowOffer::class, 'offer_id');
    }

    /**
     * Record one funnel event. Context is recursively masked before persisting
     * (never store raw card/token data). Swallows its own exceptions so analytics
     * never blocks a charge.
     *
     * @param array<string, mixed> $attributes
     */
    public static function record(array $attributes): void
    {
        try {
            $attributes['shop_id'] = $attributes['shop_id'] ?? Tenant::id();
            $attributes['occurred_at'] = $attributes['occurred_at'] ?? now();
            $attributes['created_at'] = now();

            if (isset($attributes['context']) && is_array($attributes['context'])) {
                $attributes['context'] = ResponseMasker::mask($attributes['context']);
            }

            if ($attributes['event_type'] instanceof OfferEventType) {
                $attributes['event_type'] = $attributes['event_type']->value;
            }

            static::query()->create($attributes);
        } catch (Throwable $e) {
            Log::warning('upsell.offer_event.record_failed', [
                'event_type' => is_object($attributes['event_type'] ?? null)
                    ? ($attributes['event_type']->value ?? 'unknown')
                    : ($attributes['event_type'] ?? 'unknown'),
                'exception' => $e::class,
            ]);
        }
    }
}
