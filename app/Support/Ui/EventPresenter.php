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
        // The guarded state machine actually writes 'status_changed' (Timeline::KIND_STATUS_CHANGED);
        // map it to the same label so a real transition isn't shown as a humanized fallback.
        'status_changed' => ['info', 'timeline.kind.state_changed'],
        'plan_edited' => ['info', 'timeline.kind.plan_edited'],
        'plan_completed' => ['success', 'timeline.kind.plan_completed'],
        'plan_cancelled' => ['info', 'timeline.kind.plan_cancelled'],
        'plan_paused' => ['info', 'timeline.kind.plan_paused'],
        'fulfillment_released' => ['success', 'timeline.kind.fulfillment_released'],
        'first_payment_welcome_email_sent' => ['info', 'timeline.kind.email_sent'],
        'manual_payment_email_sent' => ['info', 'timeline.kind.email_sent'],
        'manual_payment_email_resent' => ['info', 'timeline.kind.email_sent'],
        'reminder_email_sent' => ['info', 'timeline.kind.email_sent'],
        'cancellation_email_sent' => ['info', 'timeline.kind.email_sent'],
        'charge_succeeded_email_sent' => ['info', 'timeline.kind.email_sent'],
        'charge_failed_email_sent' => ['info', 'timeline.kind.email_sent'],
        'webhook_received' => ['info', 'timeline.kind.webhook_received'],
    ];

    public const FALLBACK = ['info', 'timeline.kind.generic'];

    /**
     * Previewable email-event kind => the MerchantMailSettings template that event
     * rendered. Lets the Timeline "Preview email" action show the merchant exactly
     * which template (their custom copy or the platform default) was sent, via
     * EmailPreviewRenderer. Covers every ActivityEvent::PREVIEWABLE_EMAIL_KINDS; the
     * two manual variants (sent + resent) both map to the manual template.
     *
     * @var array<string, string>
     */
    public const EMAIL_TEMPLATE_FOR_KIND = [
        'first_payment_welcome_email_sent' => 'first_payment_welcome',
        'manual_payment_email_sent' => 'manual_recurring_payment',
        'manual_payment_email_resent' => 'manual_recurring_payment',
        'reminder_email_sent' => 'recurring_payment_reminder',
        'cancellation_email_sent' => 'plan_cancelled',
        'charge_succeeded_email_sent' => 'charge_succeeded',
        'charge_failed_email_sent' => 'charge_failed',
    ];

    /** The mail template an email-event previews, or null when not previewable. */
    public static function emailTemplate(ActivityEvent $event): ?string
    {
        return self::EMAIL_TEMPLATE_FOR_KIND[$event->kind] ?? null;
    }

    /**
     * Detail keys that are SAFE to surface in the UI. Anything else (notably
     * invoice_url / document_url / raw token / payplus_* secrets) is dropped.
     */
    public const SAFE_DETAIL_KEYS = ['amount', 'currency', 'sequence', 'from', 'to', 'context', 'reason', 'changed'];

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

        // A platform admin acting on the merchant's behalf (W2): actor is
        // "platform_admin:{id}". Surfaced distinctly so the merchant Timeline shows
        // WHO touched their data — the app owner, not "system".
        if (str_starts_with($actor, \App\Support\PlatformContext::ACTOR_PREFIX)) {
            return __('common.actor.platform_admin');
        }

        // A merchant / staff user acting in the admin (W25): actor is "admin:{id}". Resolve the
        // actual name so the merchant sees WHO changed the subscription, not a generic "Admin".
        if (str_starts_with($actor, \App\Support\PlatformContext::ADMIN_PREFIX)) {
            return self::adminName((int) substr($actor, strlen(\App\Support\PlatformContext::ADMIN_PREFIX)));
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
        // A plan edit (W25): render each changed field as "old → new" (amount formatted as money,
        // dates/plain values as-is). Only the whitelisted `changed` shape is read.
        foreach ((array) ($safe['changed'] ?? []) as $field => $change) {
            if (! is_array($change) || ! array_key_exists('to', $change)) {
                continue;
            }
            $parts[] = self::changePart((string) $field, $change, (string) ($safe['currency'] ?? Money::DEFAULT_CURRENCY));
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** "Field: old → new" for a single edited field. Amount fields format as money. */
    private static function changePart(string $field, array $change, string $currency): string
    {
        $format = static function ($v) use ($field, $currency): string {
            if ($v === null || $v === '') {
                return '—';
            }

            return $field === 'amount'
                ? Money::format((float) $v, $currency)
                : (string) $v;
        };

        $label = __('timeline.field.' . $field);
        if ($label === 'timeline.field.' . $field) {
            $label = ucfirst(str_replace('_', ' ', $field));
        }

        return $label . ': ' . $format($change['from'] ?? null) . ' → ' . $format($change['to'] ?? null);
    }

    /** The display name for an "admin:{id}" actor (request-static cache), else the generic label. */
    private static array $adminNameCache = [];

    private static function adminName(int $id): string
    {
        if ($id <= 0) {
            return __('common.actor.admin');
        }
        if (! array_key_exists($id, self::$adminNameCache)) {
            $user = \App\Models\User::query()->find($id);
            $name = $user !== null ? trim((string) ($user->name ?: $user->email)) : '';
            self::$adminNameCache[$id] = $name !== '' ? $name : __('common.actor.admin');
        }

        return self::$adminNameCache[$id];
    }
}
