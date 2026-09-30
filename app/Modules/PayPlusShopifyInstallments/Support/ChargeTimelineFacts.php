<?php

namespace App\Modules\PayPlusShopifyInstallments\Support;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Support\PlatformContext;
use Throwable;

/**
 * The SAFE facts a charge row on the Timeline carries, so the row can say which
 * charge it was, for how much, on which card and why it ran — not only that
 * "a charge happened".
 *
 * Additive bookkeeping standing next to money: every read here is wrapped, and a
 * failure returns fewer facts, never an exception into the charge path. Only
 * display-safe values leave this class — never a token, never a transaction uid
 * (a uid can be charged against), never a URL.
 */
final class ChargeTimelineFacts
{
    // === CONSTANTS ===

    /** Why the charge ran. Rendered through timeline.trigger.* (EventPresenter). */
    public const TRIGGER_MERCHANT = 'merchant';

    public const TRIGGER_RETRY = 'retry';

    public const TRIGGER_AUTO_RENEWAL = 'auto_renewal';

    public const TRIGGER_SCHEDULE = 'schedule';

    /**
     * @param  int  $sequence  the payment slot this charge is for
     * @param  int  $priorAttempts  failed attempts on this slot BEFORE this one
     * @return array<string, mixed>
     */
    public static function for(
        InstallmentPlan $plan,
        PaymentType $type,
        ?int $sequence,
        ?float $amount,
        int $priorAttempts = 0,
    ): array {
        try {
            $method = $plan->activePaymentMethod();
            $recurring = $plan->plan_kind === PlanKind::RECURRING;

            return array_filter([
                'type' => $type->value,
                'trigger' => self::trigger($type, $priorAttempts),
                // Installments count their slots ("payment 3"); a subscription counts
                // its charges ("charge #3"). Same number, two honest names.
                'sequence' => $recurring ? null : $sequence,
                'charge_number' => $recurring ? $sequence : null,
                'amount' => $amount === null ? null : round($amount, 2),
                'currency' => $plan->currency ?: null,
                'card_brand' => $method?->card_brand ?: null,
                'card_last_four' => $method?->card_last_four ?: null,
            ], static fn ($v): bool => $v !== null);
        } catch (Throwable) {
            return ['type' => $type->value];
        }
    }

    public static function trigger(PaymentType $type, int $priorAttempts): string
    {
        if (PlatformContext::actingActor() !== null) {
            return self::TRIGGER_MERCHANT;
        }

        if ($priorAttempts > 0) {
            return self::TRIGGER_RETRY;
        }

        return $type === PaymentType::RECURRING ? self::TRIGGER_AUTO_RENEWAL : self::TRIGGER_SCHEDULE;
    }
}
