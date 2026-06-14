<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * Timeline / audit event. Every charge, refund, state transition, email,
 * webhook, and admin action is recorded as a typed event. The human-facing
 * companion to the payment ledger. Append-only (created_at only). Tenant-scoped.
 *
 * Generalizes the reference engine's InstallmentPlanEvent.
 */
class ActivityEvent extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    public const UPDATED_AT = null;            // append-only

    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_CUSTOMER = 'customer';
    public const ACTOR_WEBHOOK = 'webhook';

    /** Email kinds that are previewable inline in the Timeline (see EmailPreviewRenderer). */
    public const PREVIEWABLE_EMAIL_KINDS = [
        'first_payment_welcome_email_sent',
        'manual_payment_email_sent',
        'manual_payment_email_resent',
        'reminder_email_sent',
        'cancellation_email_sent',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function isEmailPreviewable(): bool
    {
        return in_array($this->kind, self::PREVIEWABLE_EMAIL_KINDS, true);
    }
}
