<?php

namespace Tests\Feature\Mail;

use App\Events\ChargeFailed;
use App\Events\ChargeSucceeded;
use App\Mail\ChargeFailedMail;
use App\Mail\ChargeSucceededMail;
use App\Mail\FirstPaymentWelcomeMail;
use App\Models\ActivityEvent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Part B: charge-attempt notification listeners. The right mail is sent, the
 * matching previewable email-kind ActivityEvent is recorded, and a re-fired event
 * never re-sends (idempotent via the plan meta marker).
 */
final class ChargeNotificationListenerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_first_successful_charge_sends_welcome_and_records_email_kind(): void
    {
        Mail::fake();
        [$shop, $plan, $payment] = $this->makePlanWithPayment();

        // No prior succeeded payment => first payment. (Positional args: Laravel's
        // variadic dispatch() does not forward NAMED params to the constructor.)
        ChargeSucceeded::dispatch($shop->id, $plan, $payment, true, false);

        Mail::assertSent(FirstPaymentWelcomeMail::class, 1);
        Mail::assertNotSent(ChargeSucceededMail::class);

        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'kind' => 'first_payment_welcome_email_sent',
        ]);
        $this->assertTrue(in_array('first_payment_welcome_email_sent', ActivityEvent::PREVIEWABLE_EMAIL_KINDS, true));
    }

    public function test_subsequent_successful_charge_sends_confirmation(): void
    {
        Mail::fake();
        [$shop, $plan, $payment] = $this->makePlanWithPayment();

        ChargeSucceeded::dispatch($shop->id, $plan, $payment, false, false);

        Mail::assertSent(ChargeSucceededMail::class, 1);
        Mail::assertNotSent(FirstPaymentWelcomeMail::class);

        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $shop->id,
            'kind' => 'charge_succeeded_email_sent',
        ]);
    }

    public function test_succeeded_listener_is_idempotent_on_refire(): void
    {
        Mail::fake();
        [$shop, $plan, $payment] = $this->makePlanWithPayment();

        ChargeSucceeded::dispatch($shop->id, $plan->fresh(), $payment, true);
        ChargeSucceeded::dispatch($shop->id, $plan->fresh(), $payment, true);

        // Second fire is a no-op (meta marker) — exactly one welcome email.
        Mail::assertSent(FirstPaymentWelcomeMail::class, 1);
        // Count inside the tenant context (ActivityEvent is shop-scoped).
        $eventCount = Tenant::run($shop, fn () => ActivityEvent::query()
            ->where('kind', 'first_payment_welcome_email_sent')
            ->count());
        $this->assertSame(1, $eventCount);
    }

    public function test_failed_charge_sends_notice_and_records_email_kind(): void
    {
        Mail::fake();
        [$shop, $plan, $payment] = $this->makePlanWithPayment();

        Tenant::run($shop, fn () => $payment->forceFill([
            'attempt_count' => 1,
            'next_retry_at' => now()->addHours(4),
        ])->save());

        ChargeFailed::dispatch($shop->id, $plan, $payment->fresh(), 'declined', 'Card declined', true);

        Mail::assertSent(ChargeFailedMail::class, 1);
        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $shop->id,
            'kind' => 'charge_failed_email_sent',
        ]);
    }

    public function test_failed_listener_is_idempotent_per_attempt(): void
    {
        Mail::fake();
        [$shop, $plan, $payment] = $this->makePlanWithPayment();

        Tenant::run($shop, fn () => $payment->forceFill(['attempt_count' => 1])->save());

        ChargeFailed::dispatch($shop->id, $plan->fresh(), $payment->fresh(), 'declined', 'Card declined');
        ChargeFailed::dispatch($shop->id, $plan->fresh(), $payment->fresh(), 'declined', 'Card declined');

        Mail::assertSent(ChargeFailedMail::class, 1);
    }

    public function test_listener_binds_the_correct_shop_even_after_another_shop_ran(): void
    {
        Mail::fake();
        [$shopA, $planA, $paymentA] = $this->makePlanWithPayment('iso-a.myshopify.com', 'a@example.com');
        [$shopB, $planB, $paymentB] = $this->makePlanWithPayment('iso-b.myshopify.com', 'b@example.com');

        // Bind A, then handle B's event — the listener must rebind to B.
        Tenant::set($shopA);
        ChargeSucceeded::dispatch($shopB->id, $planB, $paymentB, true);

        Mail::assertSent(FirstPaymentWelcomeMail::class, function (FirstPaymentWelcomeMail $mail) use ($shopB): bool {
            return $mail->shop->is($shopB) && $mail->hasTo('b@example.com');
        });

        // The recorded event belongs to shop B, not the bound shop A.
        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $shopB->id,
            'kind' => 'first_payment_welcome_email_sent',
        ]);
        $this->assertDatabaseMissing('activity_events', [
            'shop_id' => $shopA->id,
            'kind' => 'first_payment_welcome_email_sent',
        ]);
    }

    /** @return array{0: Shop, 1: InstallmentPlan, 2: InstallmentPayment} */
    private function makePlanWithPayment(string $domain = 'notify.myshopify.com', string $email = 'dana@example.com'): array
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => 'Notify Store',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        [$plan, $payment] = Tenant::run($shop, function () use ($email) {
            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::INSTALLMENTS->value,
                'total_amount' => 600,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'customer_name' => 'Dana',
                'customer_email' => $email,
                'meta' => ['product_title' => 'Widget', 'installment_count' => 6],
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            $payment = InstallmentPayment::create([
                'plan_id' => $plan->id,
                'payment_type' => PaymentType::INSTALLMENT->value,
                'sequence' => 1,
                'amount' => 100,
                'currency' => 'ILS',
            ]);
            $payment->forceFill(['status' => PaymentStatus::SUCCEEDED->value, 'attempt_count' => 0])->save();

            return [$plan, $payment];
        });

        return [$shop, $plan, $payment];
    }
}
