<?php

namespace App\Domain\OutboundWebhooks;

use App\Models\PaymentLedger;

/**
 * What a merchant's endpoint can subscribe to — the whole vocabulary, in one place.
 *
 *   order.paid                  money for a NEW purchase landed: a store checkout
 *                               paid on the PayPlus page, a deposit, a thank-you
 *                               upsell, an add-on bought in the account area.
 *   recurring_charge.succeeded  a subscription cycle or an installment was charged.
 *   recurring_charge.failed     a subscription cycle or an installment was declined
 *                               (with whether we will try again, and when).
 *   subscription.activated      a subscription became active for the first time.
 *   subscription.status_changed a subscription moved between statuses.
 *
 * Which ledger context is which topic is decided HERE and nowhere else, so the
 * live events and the screen's samples cannot disagree about it.
 */
final class WebhookTopic
{
    // === CONSTANTS ===
    public const ORDER_PAID = 'order.paid';

    public const RECURRING_SUCCEEDED = 'recurring_charge.succeeded';

    public const RECURRING_FAILED = 'recurring_charge.failed';

    /**
     * A subscription became active for the FIRST time: its first payment landed
     * (or it was activated). Sent once per subscription, ever. The moment an
     * outside system should open access.
     */
    public const SUBSCRIPTION_ACTIVATED = 'subscription.activated';

    /**
     * A subscription moved between statuses (active, paused, failed, cancelled,
     * completed…), with the status it left. Every move, including the first
     * activation, so a receiver can follow one topic to mirror the status.
     */
    public const SUBSCRIPTION_STATUS_CHANGED = 'subscription.status_changed';

    public const ALL = [
        self::ORDER_PAID, self::RECURRING_SUCCEEDED, self::RECURRING_FAILED,
        self::SUBSCRIPTION_ACTIVATED, self::SUBSCRIPTION_STATUS_CHANGED,
    ];

    /** The topics about a subscription's status rather than a charge. */
    public const SUBSCRIPTION_TOPICS = [self::SUBSCRIPTION_ACTIVATED, self::SUBSCRIPTION_STATUS_CHANGED];

    /** Ledger contexts that are a NEW purchase (the money that makes an order). */
    public const ORDER_CONTEXTS = [
        PaymentLedger::CONTEXT_DEPOSIT,
        PaymentLedger::CONTEXT_GATEWAY,
        PaymentLedger::CONTEXT_UPSELL,
        PaymentLedger::CONTEXT_ACCOUNT_OFFER,
    ];

    /** Ledger contexts that are a cycle of an existing plan. */
    public const RECURRING_CONTEXTS = [
        PaymentLedger::CONTEXT_INSTALLMENT,
        PaymentLedger::CONTEXT_RECURRING,
        PaymentLedger::CONTEXT_RETRY,
        PaymentLedger::CONTEXT_MANUAL,
    ];

    /** The topic a ledger row that just SUCCEEDED announces, or null for none. */
    public static function forSucceededContext(string $context): ?string
    {
        return match (true) {
            in_array($context, self::ORDER_CONTEXTS, true) => self::ORDER_PAID,
            in_array($context, self::RECURRING_CONTEXTS, true) => self::RECURRING_SUCCEEDED,
            default => null,
        };
    }

    /** @return array<string, string> topic => translated label */
    public static function options(): array
    {
        $options = [];
        foreach (self::ALL as $topic) {
            $options[$topic] = __('webhooks.topic.'.$topic);
        }

        return $options;
    }
}
