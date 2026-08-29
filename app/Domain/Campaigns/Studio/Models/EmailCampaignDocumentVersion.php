<?php

namespace App\Domain\Campaigns\Studio\Models;

use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One state a studio document was in — immutable, append-only.
 *
 * Undo restores one of these; a restore is itself a NEW version; nothing here
 * is ever updated or rewritten. DocumentService is the only writer.
 */
class EmailCampaignDocumentVersion extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'email_campaign_document_versions';

    /** Who moved the document. */
    public const CAUSE_MANUAL = 'manual';

    public const CAUSE_AI_PATCH = 'ai_patch';

    public const CAUSE_RESTORE = 'restore';

    public const CAUSE_INIT = 'init';

    public const CAUSES = [self::CAUSE_MANUAL, self::CAUSE_AI_PATCH, self::CAUSE_RESTORE, self::CAUSE_INIT];

    /** Immutable rows: created_at only. */
    public const UPDATED_AT = null;

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'document' => 'array',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }
}
