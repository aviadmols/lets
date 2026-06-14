<?php

namespace App\Modules\PayPlusShopifyInstallments\Support;

use App\Models\ActivityEvent;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes Timeline (activity_events) rows. Ported behaviour from the reference
 * engine's recordActivity(): it SWALLOWS its own exceptions — a failed audit
 * write must never block or roll back the money path. Tenant is taken from the
 * bound context (or an explicit shopId for system/cross-tenant callers).
 *
 * The human-facing companion to the payment ledger. Phase 3.5 (notifications)
 * extends `kind` taxonomy + adds the email-preview surface; this is the core.
 */
final class Timeline
{
    // === CONSTANTS — kind taxonomy (extended in Phase 3.5) ===
    public const KIND_STATUS_CHANGED = 'status_changed';
    public const KIND_CHARGE_ATTEMPT_STARTED = 'charge_attempt_started';
    public const KIND_CHARGE_SUCCEEDED = 'charge_succeeded';
    public const KIND_CHARGE_FAILED = 'charge_failed';
    public const KIND_CHARGE_RETRY_SCHEDULED = 'charge_retry_scheduled';
    public const KIND_PLAN_COMPLETED = 'plan_completed';
    public const KIND_REFUNDED = 'refunded';
    /** No customer_consents row for (shop, customer, context) — charge skipped, left for admin. */
    public const KIND_CONSENT_MISSING = 'consent_missing';

    /**
     * Record a Timeline event. Never throws.
     *
     * @param array<string, mixed> $details
     */
    public static function record(
        string $kind,
        array $details = [],
        ?int $planId = null,
        ?int $paymentId = null,
        string $actor = ActivityEvent::ACTOR_SYSTEM,
        ?int $shopId = null,
    ): void {
        try {
            ActivityEvent::query()->create([
                'shop_id' => $shopId ?? Tenant::id(),
                'plan_id' => $planId,
                'payment_id' => $paymentId,
                'actor' => $actor,
                'kind' => $kind,
                'details' => $details,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Audit failure must not break the charge — log and move on.
            Log::warning('timeline.record_failed', [
                'kind' => $kind,
                'exception' => $e::class,
            ]);
        }
    }
}
