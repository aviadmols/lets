<?php

namespace App\Domain\Campaigns\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A gift campaign: the merchant's rule ("at least N charged cycles"), the gift, and
 * the run that turned the two into store orders.
 *
 * The product title and unit price on this row are SNAPSHOTS, not lookups. A
 * campaign records what was given away and what it was worth on the day — reading
 * today's catalog price later would quietly rewrite history and disagree with the
 * orders the campaign itself created.
 */
class GiftCampaign extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'gift_campaigns';

    /** Nothing generated yet — the merchant is still choosing. */
    public const STATUS_DRAFT = 'draft';

    /** Recipients enrolled, orders being created in the background. */
    public const STATUS_GENERATING = 'generating';

    /** Every recipient reached a terminal state, all of them successfully. */
    public const STATUS_COMPLETED = 'completed';

    /** Finished, but at least one recipient was skipped or failed. */
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_GENERATING,
        self::STATUS_COMPLETED,
        self::STATUS_COMPLETED_WITH_ERRORS,
    ];

    /** shop_id is auto-stamped by BelongsToShop and can never be mass-assigned. */
    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'min_cycles' => 'integer',
            'source_product_ids' => 'array',
            'source_emails' => 'array',
            'unit_price' => 'decimal:2',
            'items' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(GiftRecipient::class, 'gift_campaign_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * The products this campaign is limited to, as LOCAL Product ids. An empty
     * list means every product — the rule is "at least N cycles", full stop.
     *
     * @return array<int, int>
     */
    public function sourceProductIds(): array
    {
        return array_values(array_filter(array_map(
            static fn ($id): int => (int) $id,
            (array) ($this->source_product_ids ?? []),
        )));
    }

    /**
     * The specific people this campaign is narrowed to, lowercased. An empty
     * list means the rule alone decides — every campaign before this filter.
     *
     * @return array<int, string>
     */
    public function sourceEmails(): array
    {
        return array_values(array_filter(array_map(
            static fn ($email): string => mb_strtolower(trim((string) $email)),
            (array) ($this->source_emails ?? []),
        ), static fn (string $email): bool => $email !== ''));
    }

    /**
     * The gift as a LIST of snapshot lines — every campaign has one, whether it
     * was saved before or after multi-item gifts existed. The legacy
     * single-product columns ARE the list for an old row (and always mirror the
     * first item on a new one), so every consumer reads through here and no
     * order-building path has to know which era the campaign came from.
     *
     * @return list<array{product_id: ?int, product_variant_id: ?int, title: string, unit_price: float}>
     */
    public function giftItems(): array
    {
        $rows = [];

        foreach ((array) ($this->items ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $price = round((float) ($row['unit_price'] ?? 0), 2);
            if ($price <= 0.0) {
                continue; // a snapshot without a value never made it past save().
            }

            $rows[] = [
                'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : null,
                'product_variant_id' => isset($row['product_variant_id']) ? (int) $row['product_variant_id'] : null,
                'title' => trim((string) ($row['title'] ?? '')),
                'unit_price' => $price,
            ];
        }

        if ($rows !== []) {
            return $rows;
        }

        // The legacy shape: one product, in its own columns.
        return [[
            'product_id' => $this->product_id !== null ? (int) $this->product_id : null,
            'product_variant_id' => $this->product_variant_id !== null ? (int) $this->product_variant_id : null,
            'title' => trim((string) ($this->product_title ?? '')),
            'unit_price' => round((float) $this->unit_price, 2),
        ]];
    }

    /** What the whole gift is worth — the number the order note declares. */
    public function totalValue(): float
    {
        return round(array_sum(array_column($this->giftItems(), 'unit_price')), 2);
    }

    /** Has this campaign already been generated? Drives the UI's Generate button. */
    public function isGenerated(): bool
    {
        return $this->generated_at !== null;
    }

    /**
     * Settle the campaign from its recipients' terminal states. Called when the last
     * one finishes; "completed" claims every gift landed, so anything skipped or
     * failed must downgrade it rather than be rounded away.
     */
    public function settleStatus(): void
    {
        $pending = $this->recipients()
            ->whereIn('status', [GiftRecipient::STATUS_PENDING, GiftRecipient::STATUS_CREATING])
            ->exists();

        if ($pending) {
            return;
        }

        $problems = $this->recipients()
            ->whereIn('status', [GiftRecipient::STATUS_SKIPPED, GiftRecipient::STATUS_FAILED])
            ->exists();

        $this->forceFill([
            'status' => $problems ? self::STATUS_COMPLETED_WITH_ERRORS : self::STATUS_COMPLETED,
        ])->save();
    }
}
