<?php

namespace App\Domain\Ai\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One AI call, win or lose — the bill, the budget and the debugging all read
 * from here. Immutable rows; AiGateway is the only writer.
 */
class AiUsageEvent extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'ai_usage_events';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_OVER_BUDGET = 'over_budget';

    /** Too many calls from one shop in the rate window — refused before the provider. */
    public const STATUS_RATE_LIMITED = 'rate_limited';

    /** A call's row written before the provider is asked, holding its estimate. */
    public const STATUS_RESERVED = 'reserved';

    /** Immutable rows: created_at only. */
    public const UPDATED_AT = null;

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reserved_tokens' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** Tokens the whole platform spent (or has reserved) today — the budget's denominator. */
    public static function platformTokensToday(): int
    {
        return (int) static::acrossAllTenants()
            ->where('created_at', '>=', now()->startOfDay())
            ->sum(DB::raw('input_tokens + output_tokens + reserved_tokens'));
    }

    /**
     * Tokens ONE shop spent (or has reserved) today — its own cap's denominator.
     * Explicit shop_id: the gateway may run with no tenant bound (a queued job).
     */
    public static function shopTokensToday(int $shopId): int
    {
        return (int) static::acrossAllTenants()
            ->where('shop_id', $shopId)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum(DB::raw('input_tokens + output_tokens + reserved_tokens'));
    }
}
