<?php

namespace App\Domain\Campaigns\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One subscriber's enrolment in one gift campaign — and the campaign's only
 * protection against gifting the same person twice.
 *
 * Neither platform accepts an idempotency key on order creation, so unlike a charge
 * there is nothing store-side to collapse a duplicate. This row does it: the unique
 * (campaign, source_type, source_id) index makes a re-run a no-op, and the row is
 * moved to `creating` BEFORE the API call so a redelivered job finds it and stops.
 *
 * Which is why `creating` is NEVER retried automatically: the order may already
 * exist in the store. A missing gift is a button click; a duplicate gift is a
 * package the merchant pays to ship twice. `failed` is different — the platform
 * rejected the call, so no order exists and a retry is safe.
 */
class GiftRecipient extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'gift_recipients';

    /** Enrolled, no order attempted yet — the only state a job may act on. */
    public const STATUS_PENDING = 'pending';
    /** The API call is in flight, or died mid-flight. A HUMAN resolves this. */
    public const STATUS_CREATING = 'creating';
    public const STATUS_CREATED = 'created';
    /** We chose not to create the order (see `reason`) — no order exists. */
    public const STATUS_SKIPPED = 'skipped';
    /** The platform REJECTED the call — no order exists, so a retry is safe. */
    public const STATUS_FAILED = 'failed';

    /** The two subscription rails a recipient can come from. */
    public const SOURCE_PLAN = 'plan';
    public const SOURCE_CONTRACT = 'contract';

    /** Reason codes; the UI translates each one. */
    public const REASON_NO_ADDRESS = 'no_address';
    public const REASON_ADDRESS_ACCESS_PENDING = 'address_access_pending';
    public const REASON_NO_PRICE = 'no_price';
    public const REASON_API_ERROR = 'api_error';

    /** Where the shipping address came from — shown so the merchant can judge it. */
    public const ADDRESS_FROM_PROFILE = 'customer_profile';
    public const ADDRESS_FROM_ORDER = 'origin_order';

    /** status is guarded: it moves only through the mark* helpers below. */
    protected $guarded = ['id', 'shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'cycles_at_generate' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(GiftCampaign::class, 'gift_campaign_id');
    }

    /** A human label for the list: the name, else the email, else the source id. */
    public function label(): string
    {
        foreach ([$this->customer_name, $this->customer_email] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '#'.$this->source_id;
    }

    /**
     * Claim this recipient for an order attempt. Returns FALSE when someone else
     * already claimed it — an atomic pending → creating move, so two deliveries of
     * the same job cannot both reach the platform.
     */
    public function claim(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_PENDING)
            ->update(['status' => self::STATUS_CREATING, 'updated_at' => now()]) === 1;
    }

    public function markCreated(string $orderId, ?string $addressSource): void
    {
        $this->forceFill([
            'status' => self::STATUS_CREATED,
            'external_order_id' => $orderId,
            'address_source' => $addressSource,
            'reason' => null,
        ])->save();
    }

    public function markSkipped(string $reason): void
    {
        $this->forceFill(['status' => self::STATUS_SKIPPED, 'reason' => $reason])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'reason' => mb_substr($reason, 0, 255),
        ])->save();
    }

    /** Put a REJECTED attempt back in the queue. Never valid for `creating`. */
    public function resetForRetry(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_FAILED)
            ->update(['status' => self::STATUS_PENDING, 'reason' => null, 'updated_at' => now()]) === 1;
    }
}
