<?php

namespace App\Domain\OutboundWebhooks\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event, to one endpoint. `source` names what the event is ABOUT (a ledger
 * row, a failed payment attempt, a Shopify billing attempt); `payload` is the
 * JSON exactly as it was sent, built on the first attempt and frozen so every
 * retry repeats the same body the merchant may already have half-processed.
 */
class WebhookDelivery extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'webhook_deliveries';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'source' => 'array',
            'payload' => 'array',
            'is_test' => 'boolean',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true);
    }
}
