<?php

namespace App\Domain\OutboundWebhooks\Models;

use App\Domain\OutboundWebhooks\WebhookTopic;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One merchant URL that receives LETS events. Tenant-owned (BelongsToShop); the
 * signing secret is encrypted at rest and only ever leaves the database to sign
 * a request or to be shown to the merchant who owns it.
 */
class WebhookEndpoint extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'webhook_endpoints';

    public const SECRET_PREFIX = 'whsec_';

    private const SECRET_LENGTH = 40;

    protected $guarded = ['id', 'shop_id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'topics' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public static function newSecret(): string
    {
        return self::SECRET_PREFIX.Str::random(self::SECRET_LENGTH);
    }

    /** The topics this endpoint listens to — only the known ones, whatever the column holds. */
    public function subscribedTopics(): array
    {
        return array_values(array_intersect(WebhookTopic::ALL, (array) ($this->topics ?? [])));
    }

    public function listensTo(string $topic): bool
    {
        return in_array($topic, $this->subscribedTopics(), true);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_endpoint_id');
    }
}
