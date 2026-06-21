<?php

namespace App\Listeners;

use App\Events\ChargeSucceeded;
use App\Mail\ChargeSucceededMail;
use App\Mail\FirstPaymentWelcomeMail;
use App\Mail\Support\MailSettingsConfigurator;
use App\Models\ActivityEvent;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the success email after a charge: the FIRST successful charge gets the
 * welcome email; every later success gets the payment-received confirmation.
 *
 * TENANT-SAFE: binds the tenant explicitly from $event->shopId (never inferred
 * from global state) so it renders the right shop's templates even on a worker
 * that just handled another shop.
 *
 * MONEY-SAFE: the ledger row + Timeline charge_succeeded event were already
 * written by the orchestrator BEFORE this fired — the email is a side effect. The
 * whole send is wrapped in try/catch + Log::warning so a mail failure can never
 * block or undo a charge.
 *
 * IDEMPOTENT: guarded by meta.{kind}_sent_at on the plan, so a re-fired event
 * (queue retry, double dispatch) never re-sends.
 */
final class SendChargeSucceededNotification
{
    public function handle(ChargeSucceeded $event): void
    {
        Tenant::run($this->resolveShop($event->shopId), function () use ($event): void {
            $plan = $event->plan;

            $template = $event->isFirstPayment
                ? MerchantMailSettings::TEMPLATE_FIRST_PAYMENT_WELCOME
                : MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED;

            $kind = ActivityEvent::EMAIL_KIND_FOR_TEMPLATE[$template];
            $sentAtKey = $kind.'_at';

            // First-payment welcome is once-per-plan; the per-charge confirmation
            // is once-per-payment-slot (so each installment gets its own receipt).
            $guardKey = $event->isFirstPayment
                ? $sentAtKey
                : $sentAtKey.':payment:'.$event->payment->getKey();

            if ($this->alreadySent($plan->meta ?? [], $guardKey)) {
                return;
            }

            $recipient = (string) ($plan->customer_email ?? '');
            if ($recipient === '') {
                Log::warning('mail.charge_succeeded.no_recipient', [
                    'shop_id' => $event->shopId,
                    'plan_id' => $plan->getKey(),
                ]);

                return;
            }

            try {
                $shop = Tenant::current();
                MailSettingsConfigurator::apply($shop); // per-shop SMTP override (no-op unless enabled)

                $mailable = $event->isFirstPayment
                    ? new FirstPaymentWelcomeMail(shop: $shop, plan: $plan, payment: $event->payment)
                    : new ChargeSucceededMail(shop: $shop, plan: $plan, payment: $event->payment);

                Mail::to($recipient)->send($mailable);

                $this->markSent($plan, $guardKey);

                Timeline::record(
                    kind: $kind,
                    details: ['template' => $template, 'payment_id' => $event->payment->getKey()],
                    planId: $plan->getKey(),
                    paymentId: $event->payment->getKey(),
                    shopId: $event->shopId,
                );
            } catch (\Throwable $e) {
                // NEVER block the charge — the money already moved + is ledgered.
                Log::warning('mail.charge_succeeded.send_failed', [
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

    /** @param array<string, mixed> $meta */
    private function alreadySent(array $meta, string $key): bool
    {
        return ! empty($meta[$key] ?? null);
    }

    private function markSent(\App\Models\InstallmentPlan $plan, string $key): void
    {
        $meta = (array) ($plan->meta ?? []);
        $meta[$key] = now()->toIso8601String();
        $plan->meta = $meta;
        $plan->save();
    }
}
