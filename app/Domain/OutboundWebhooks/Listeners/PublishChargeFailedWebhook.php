<?php

namespace App\Domain\OutboundWebhooks\Listeners;

use App\Domain\OutboundWebhooks\WebhookPayload;
use App\Domain\OutboundWebhooks\WebhookPublisher;
use App\Domain\OutboundWebhooks\WebhookTopic;
use App\Events\ChargeFailed;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;

/**
 * A declined cycle or installment → `recurring_charge.failed`, once per ATTEMPT
 * (the key carries the attempt number, so attempt 2 is a new event and a
 * re-fired attempt 2 is not). Carries whether we will retry and when.
 *
 * Not announced: a one-shot account-area offer (it is a purchase, not a cycle —
 * its plan is about to be cancelled by the caller) and a deposit (no
 * subscription is running yet).
 */
final class PublishChargeFailedWebhook
{
    // === CONSTANTS ===
    private const CYCLE_TYPES = [PaymentType::INSTALLMENT, PaymentType::RECURRING];

    public function __construct(private readonly WebhookPublisher $publisher) {}

    public function handle(ChargeFailed $event): void
    {
        $payment = $event->payment;

        if ($event->plan->isOneShotOffer() || ! in_array($payment->payment_type, self::CYCLE_TYPES, true)) {
            return;
        }

        $attempt = (int) $payment->attempt_count;

        $this->publisher->publish(
            $event->shopId,
            WebhookTopic::RECURRING_FAILED,
            sprintf('payment:%d:attempt:%d:failed', $payment->getKey(), $attempt),
            [
                'kind' => WebhookPayload::SOURCE_PAYMENT_FAILED,
                'payment_id' => (int) $payment->getKey(),
                'attempt' => $attempt,
                'error_code' => $event->errorCode,
                'error_message' => $event->errorMessage,
                'will_retry' => $event->willRetry,
            ],
        );
    }
}
