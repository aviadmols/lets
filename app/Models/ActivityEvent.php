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

    /**
     * GDPR privacy-webhook audit kinds. Recorded with NO PII in `details` — only a
     * salted customer ref hash + per-table redaction counts (RedactionPolicy).
     */
    public const KIND_CUSTOMER_REDACTED = 'customer_redacted';
    public const KIND_SHOP_REDACTED = 'shop_redacted';
    public const KIND_CUSTOMER_DATA_EXPORTED = 'customer_data_exported';

    /** Email kinds that are previewable inline in the Timeline (see EmailPreviewRenderer). */
    public const PREVIEWABLE_EMAIL_KINDS = [
        'first_payment_welcome_email_sent',
        'manual_payment_email_sent',
        'manual_payment_email_resent',
        'reminder_email_sent',
        'cancellation_email_sent',
        'charge_succeeded_email_sent',
        'charge_failed_email_sent',
    ];

    /**
     * Map a MerchantMailSettings template key → the Timeline kind written when
     * that email is sent. Lets a listener record the right previewable kind
     * without restating the mapping in three places.
     */
    public const EMAIL_KIND_FOR_TEMPLATE = [
        'first_payment_welcome' => 'first_payment_welcome_email_sent',
        'recurring_payment_reminder' => 'reminder_email_sent',
        'manual_recurring_payment' => 'manual_payment_email_sent',
        'charge_succeeded' => 'charge_succeeded_email_sent',
        'charge_failed' => 'charge_failed_email_sent',
        'plan_cancelled' => 'cancellation_email_sent',
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
