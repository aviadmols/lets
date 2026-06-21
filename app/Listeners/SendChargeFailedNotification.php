<?php

namespace App\Listeners;

use App\Events\ChargeFailed;
use App\Mail\ChargeFailedMail;
use App\Mail\Support\MailSettingsConfigurator;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the failed-charge notice after a charge attempt fails. Tells the customer
 * the reason + the next retry date and links the portal to fix their card.
 *
 * TENANT-SAFE: binds from $event->shopId explicitly. MONEY-SAFE: fires only after
 * the ledger row + Timeline charge_failed event were written; the send is wrapped
 * in try/catch + Log::warning and NEVER blocks the charge pipeline.
 *
 * IDEMPOTENT: guarded per payment slot per attempt via
 * meta.charge_failed_email_sent_at:payment:{id}:attempt:{n}, so re-firing the
 * event (queue retry, double dispatch) never re-sends the same notice.
 */
final class SendChargeFailedNotification
{
    public function handle(ChargeFailed $event): void
    {
        Tenant::run($this->resolveShop($event->shopId), function () use ($event): void {
            $plan = $event->plan;
            $payment = $event->payment;

            $guardKey = sprintf(
                'charge_failed_email_sent_at:payment:%d:attempt:%d',
                $payment->getKey(),
                (int) $payment->attempt_count,
            );

            if (! empty(($plan->meta ?? [])[$guardKey] ?? null)) {
                return; // already notified for this slot+attempt
            }

            $recipient = (string) ($plan->customer_email ?? '');
            if ($recipient === '') {
                Log::warning('mail.charge_failed.no_recipient', [
                    'shop_id' => $event->shopId,
                    'plan_id' => $plan->getKey(),
                ]);

                return;
            }

            try {
                $shop = Tenant::current();
                MailSettingsConfigurator::apply($shop);

                Mail::to($recipient)->send(new ChargeFailedMail(
                    shop: $shop,
                    plan: $plan,
                    payment: $payment,
                    failureReason: $event->errorMessage ?? $event->errorCode,
                    nextRetryDate: $payment->next_retry_at?->format('d/m/Y'),
                ));

                $this->markSent($plan, $guardKey);

                Timeline::record(
                    kind: ActivityEvent::EMAIL_KIND_FOR_TEMPLATE[MerchantMailSettings::TEMPLATE_CHARGE_FAILED],
                    details: [
                        'template' => MerchantMailSettings::TEMPLATE_CHARGE_FAILED,
                        'payment_id' => $payment->getKey(),
                        'attempt' => (int) $payment->attempt_count,
                        'will_retry' => $event->willRetry,
                    ],
                    planId: $plan->getKey(),
                    paymentId: $payment->getKey(),
                    shopId: $event->shopId,
                );
            } catch (\Throwable $e) {
                Log::warning('mail.charge_failed.send_failed', [
                    'shop_id' => $event->shopId,
                    'plan_id' => $plan->getKey(),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    private function resolveShop(int $shopId): Shop
    {
        return Shop::query()->whereKey($shopId)->firstOrFail();
    }

    private function markSent(InstallmentPlan $plan, string $key): void
    {
        $meta = (array) ($plan->meta ?? []);
        $meta[$key] = now()->toIso8601String();
        $plan->meta = $meta;
        $plan->save();
    }
}
