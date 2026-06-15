<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A received Shopify (or PayPlus) webhook — the dedupe + audit record.
 *
 * Deliberately NOT BelongsToShop-scoped. This row is written by the webhook
 * INGESTION path, which runs BEFORE any Tenant is bound (the HMAC must be
 * verified and the shop resolved from the header first). It also legitimately
 * holds null-shop rows (a webhook for a domain we have no Shop for). The
 * ProcessShopifyWebhookJob binds the tenant explicitly from the stored shop_id
 * and reads this row by primary key — never via a tenant scope.
 *
 * Source pattern: reference engine app/Models/WebhookEvent.php (single-tenant) →
 * here it carries shop_id so the dedupe key + processing are tenant-scoped.
 */
class WebhookEvent extends Model
{
    // === CONSTANTS ===
    public const SOURCE_SHOPIFY = 'shopify';
    public const SOURCE_PAYPLUS = 'payplus';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'headers' => 'array',
            'hmac_valid' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    public function markProcessed(?string $error = null): void
    {
        $this->forceFill([
            'processed_at' => now(),
            'error' => $error,
        ])->save();
    }
}
