<?php

namespace App\Domain\Analytics\Cancellations;

use App\Domain\Account\CustomerSubscriptionActions;
use App\Domain\Account\Offers\AccountOfferAcceptService;
use App\Domain\Analytics\Subscribers\MovementLog;
use App\Domain\Refunds\RefundPlanCanceller;
use App\Models\ActivityEvent;
use App\Support\PlatformContext;

/**
 * Turns the two facts a cancellation leaves behind — WHO wrote the Timeline row
 * (activity_events.actor) and the free-text details.reason — into a channel and
 * a reason group. Pure; no database.
 *
 * CONSERVATIVE BY DESIGN (docs/analytics/data-map.md §6): LETS has no reason
 * list on its cancel flow. details.reason is either one of our own MARKERS
 * (customer_area, customer_portal, account_offer:<id>, order_refunded) — which
 * name a channel or a mechanism, never the customer's motive — or text a
 * merchant typed. Markers never pose as a reason; typed text is grouped by its
 * exact (trimmed, case-folded) wording, and nothing is guessed from it.
 */
final class CancellationReasons
{
    // === CONSTANTS ===
    public const CHANNEL_ACCOUNT_AREA = 'account_area';

    public const CHANNEL_CUSTOMER_PORTAL = 'customer_portal';

    public const CHANNEL_CUSTOMER = 'customer';

    public const CHANNEL_ADMIN = 'admin';

    public const CHANNEL_PLATFORM = 'platform';

    public const CHANNEL_PAYMENT_FAILED = 'payment_failed';

    public const CHANNEL_PLAN_SWITCH = 'plan_switch';

    public const CHANNEL_REFUND = 'refund';

    public const CHANNEL_STORE = 'store';

    public const CHANNEL_AUTOMATIC = 'automatic';

    public const CHANNELS = [
        self::CHANNEL_ACCOUNT_AREA, self::CHANNEL_CUSTOMER_PORTAL, self::CHANNEL_CUSTOMER,
        self::CHANNEL_ADMIN, self::CHANNEL_PLATFORM, self::CHANNEL_PAYMENT_FAILED,
        self::CHANNEL_PLAN_SWITCH, self::CHANNEL_REFUND, self::CHANNEL_STORE, self::CHANNEL_AUTOMATIC,
    ];

    /** The coarse "cancellation source" filter of Order-wise churn → the channels it covers. */
    public const SOURCES = [
        'all' => self::CHANNELS,
        'customer' => [self::CHANNEL_ACCOUNT_AREA, self::CHANNEL_CUSTOMER_PORTAL, self::CHANNEL_CUSTOMER],
        'admin' => [self::CHANNEL_ADMIN, self::CHANNEL_PLATFORM],
        'payment_failed' => [self::CHANNEL_PAYMENT_FAILED],
        'automatic' => [self::CHANNEL_PLAN_SWITCH, self::CHANNEL_REFUND, self::CHANNEL_STORE, self::CHANNEL_AUTOMATIC],
    ];

    /** Reason groups that are ours (translated); any other group key is merchant text. */
    public const REASON_NONE = '__none';

    public const REASON_PAYMENT_FAILED = '__payment_failed';

    public const REASON_SWITCHED = '__switched';

    public const REASON_REFUNDED = '__refunded';

    public const SYSTEM_REASONS = [self::REASON_NONE, self::REASON_PAYMENT_FAILED, self::REASON_SWITCHED, self::REASON_REFUNDED];

    /** Markers our own code writes into details.reason — mechanisms, not motives. */
    public const CHANNEL_MARKERS = [
        CustomerSubscriptionActions::ACTOR => self::CHANNEL_ACCOUNT_AREA,
        'customer_portal' => self::CHANNEL_CUSTOMER_PORTAL,
    ];

    /** Typed reasons longer than this are cut for grouping/display (a reason, not an essay). */
    public const MAX_REASON = 80;

    /** The channel a cancellation came through. */
    public static function channel(?string $actor, ?string $reason, ?string $toStatus): string
    {
        $actor = (string) $actor;
        $reason = trim((string) $reason);

        if (in_array($toStatus, MovementLog::LAPSED, true)) {
            return self::CHANNEL_PAYMENT_FAILED;
        }
        if (isset(self::CHANNEL_MARKERS[$reason])) {
            return self::CHANNEL_MARKERS[$reason];
        }
        if (isset(self::CHANNEL_MARKERS[$actor])) {
            return self::CHANNEL_MARKERS[$actor];
        }
        if (str_starts_with($reason, AccountOfferAcceptService::REASON_REPLACED)) {
            return self::CHANNEL_PLAN_SWITCH;
        }
        if ($reason === RefundPlanCanceller::REASON) {
            return self::CHANNEL_REFUND;
        }

        return match (true) {
            $actor === ActivityEvent::ACTOR_CUSTOMER => self::CHANNEL_CUSTOMER,
            str_starts_with($actor, PlatformContext::ACTOR_PREFIX) => self::CHANNEL_PLATFORM,
            str_starts_with($actor, PlatformContext::ADMIN_PREFIX) => self::CHANNEL_ADMIN,
            $actor === ActivityEvent::ACTOR_WEBHOOK => self::CHANNEL_STORE,
            default => self::CHANNEL_AUTOMATIC,
        };
    }

    /**
     * The reason GROUP key: one of SYSTEM_REASONS, or the merchant's typed text
     * case-folded (so "Too expensive" and "too expensive " are one group).
     */
    public static function reasonKey(?string $reason, ?string $toStatus): string
    {
        if (in_array($toStatus, MovementLog::LAPSED, true)) {
            return self::REASON_PAYMENT_FAILED;
        }
        $text = self::clean($reason);
        if ($text === '' || isset(self::CHANNEL_MARKERS[$text])) {
            return self::REASON_NONE;
        }
        if (str_starts_with($text, AccountOfferAcceptService::REASON_REPLACED)) {
            return self::REASON_SWITCHED;
        }
        if ($text === RefundPlanCanceller::REASON) {
            return self::REASON_REFUNDED;
        }

        return mb_strtolower($text);
    }

    /** The merchant's text as shown (trimmed, single-spaced, capped). */
    public static function clean(?string $reason): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $reason));

        return mb_strlen($text) > self::MAX_REASON ? rtrim(mb_substr($text, 0, self::MAX_REASON)).'…' : $text;
    }

    public static function isSystemReason(string $key): bool
    {
        return in_array($key, self::SYSTEM_REASONS, true);
    }

    /** @return list<string> channels covered by a source filter value */
    public static function channelsFor(string $source): array
    {
        return self::SOURCES[$source] ?? self::CHANNELS;
    }
}
