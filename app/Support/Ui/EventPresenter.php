<?php

namespace App\Support\Ui;

use App\Models\ActivityEvent;

/**
 * Humanizes an ActivityEvent for the Timeline / dashboard activity feed
 * (components §4.14). Generalizes the reference engine's PlanEventPresenter.
 *
 * HARD RULE (CLAUDE.md / ARCHITECTURE.md §6.6): never expose invoice_url /
 * document_url in the UI. summarizeDetails() whitelists safe keys only — a raw
 * document URL in the event payload is dropped here, not in the Blade.
 */
final class EventPresenter
{
    // === CONSTANTS ===

    /** kind => [tone, translation-key]. tone drives the timeline dot color. */
    public const KINDS = [
        'plan_created' => ['info', 'timeline.kind.plan_created'],
        'charge_succeeded' => ['success', 'timeline.kind.charge_succeeded'],
        'charge_failed' => ['failure', 'timeline.kind.charge_failed'],
        'retry_scheduled' => ['info', 'timeline.kind.retry_scheduled'],
        'refund_succeeded' => ['info', 'timeline.kind.refund_succeeded'],
        'state_changed' => ['info', 'timeline.kind.state_changed'],
        'plan_completed' => ['success', 'timeline.kind.plan_completed'],
        'plan_cancelled' => ['info', 'timeline.kind.plan_cancelled'],
        'plan_paused' => ['info', 'timeline.kind.plan_paused'],
        'fulfillment_released' => ['success', 'timeline.kind.fulfillment_released'],
        'first_payment_welcome_email_sent' => ['info', 'timeline.kind.email_sent'],
        'manual_payment_email_sent' => ['info', 'timeline.kind.email_sent'],
        'manual_payment_email_resent' => ['info', 'timeline.kind.email_sent'],
        'reminder_email_sent' => ['info', 'timeline.kind.email_sent'],
        'cancellation_email_sent' => ['info', 'timeline.kind.email_sent'],
        'webhook_received' => ['info', 'timeline.kind.webhook_received'],
    ];

    public const FALLBACK = ['info', 'timeline.kind.generic'];

    /**
     * Detail keys that are SAFE to surface in the UI. Anything else (notably
     * invoice_url / document_url / raw token / payplus_* secrets) is dropped.
     */
    public const SAFE_DETAIL_KEYS = ['amount', 'currency', 'sequence', 'from', 'to', 'context', 'reason'];

    public static function tone(ActivityEvent $event): string
    {
        return (self::KINDS[$event->kind] ?? self::FALLBACK)[0];
    }

    public static function label(ActivityEvent $event): string
    {
        $key = (self::KINDS[$event->kind] ?? self::FALLBACK)[1];
        $translated = __($key);

        // If a kind has no specific key yet, humanize the raw kind rather than
        // showing a missing-translation token.
        return $translated === $key
            ? ucfirst(str_replace('_', ' ', $event->kind))
            : $translated;
    }

    public static function actorLabel(ActivityEvent $event): string
    {
        $actor = (string) ($event->actor ?? ActivityEvent::ACTOR_SYSTEM);

        if (str_starts_with($actor, 'admin:')) {
            return __('common.actor.admin');
        }

        return match ($actor) {
            ActivityEvent::ACTOR_CUSTOMER => __('common.actor.customer'),
            ActivityEvent::ACTOR_WEBHOOK => __('common.actor.webhook'),
            default => __('common.actor.system'),
        };
    }

    /** Plain-language one-line summary built ONLY from whitelisted detail keys. */
    public static function summarize(ActivityEvent $event): ?string
    {
        $details = (array) ($event->details ?? []);
        $safe = array_intersect_key($details, array_flip(self::SAFE_DETAIL_KEYS));

        if ($safe === []) {
            return null;
        }

        $parts = [];
        if (isset($safe['amount'])) {
            $parts[] = Money::format((float) $safe['amount'], (string) ($safe['currency'] ?? Money::DEFAULT_CURRENCY));
        }
        if (isset($safe['sequence'])) {
            $parts[] = __('subscriptions.detail.installment_n', ['n' => $safe['sequence']]);
        }
        if (isset($safe['from'], $safe['to'])) {
            $parts[] = __('billing.status.' . $safe['from']) . ' → ' . __('billing.status.' . $safe['to']);
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
