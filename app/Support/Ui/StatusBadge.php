<?php

namespace App\Support\Ui;

/**
 * The ONE place a domain status string is mapped to a visual tone. Drives every
 * list/detail badge via the <x-rc.badge> component + the .rc-badge--{tone} CSS
 * classes in components/badge.css. Never recompute a status color inline in a
 * Blade ternary or a Filament ->color() closure — call StatusBadge::tone().
 *
 * Status strings are the CANONICAL state-machine values from ARCHITECTURE.md §3.3
 * (PlanStatus + PaymentLedgerStatus) — never a synonym. A status absent from the
 * map is a backend contract drift: it surfaces as 'gray' AND is logged-by-eye via
 * the explicit isKnown() check, rather than silently papered over.
 *
 * Tones match the spec badge map in docs/ux/00-design-system.md §4.2.
 */
final class StatusBadge
{
    // === CONSTANTS ===

    /**
     * status value => semantic tone (green|gray|teal|red|amber).
     * @var array<string, string>
     */
    public const TONES = [
        // --- InstallmentPlanStatus / RecurringPlanStatus (PlanStatus enum) ---
        'draft' => 'gray',
        'awaiting_first_payment' => 'amber',
        'active' => 'green',
        'paused' => 'gray',
        'completed' => 'green',
        'failed' => 'red',
        'cancelled' => 'gray',

        // --- PaymentLedgerStatus ---
        'pending' => 'gray',
        'succeeded' => 'green',
        // 'failed' shared above (red)
        'refunded' => 'gray',
        'retry_scheduled' => 'amber',
        // ledger 'cancelled' shared above (gray)

        // --- PayPlus connection status (settings) ---
        'connected' => 'green',
        'not_connected' => 'gray',
        'error' => 'red',
    ];

    public const FALLBACK_TONE = 'gray';

    /** The translation domain that holds the human label for a status. */
    public const LABEL_DOMAIN_PLAN = 'billing.status';
    public const LABEL_DOMAIN_LEDGER = 'billing.ledger_status';

    public static function tone(?string $status): string
    {
        return self::TONES[$status] ?? self::FALLBACK_TONE;
    }

    public static function isKnown(?string $status): bool
    {
        return $status !== null && array_key_exists($status, self::TONES);
    }
}
