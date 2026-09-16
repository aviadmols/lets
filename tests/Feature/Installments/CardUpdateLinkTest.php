<?php

namespace Tests\Feature\Installments;

use App\Domain\Installments\CardUpdateLinks;
use App\Domain\Installments\CardUpdateLinkSender;
use App\Domain\Installments\CardUpdateService;
use App\Domain\Installments\Models\CardUpdateLink;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Mail\CardUpdateLinkMail;
use App\Models\ActivityEvent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\MerchantSmsSettings;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsSenderFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The durable "update your card" link.
 *
 * The whole reason it exists: a PayPlus re-vault page is minted for a moment and
 * expires on their side, so emailing one sends a dead page. This link is ours,
 * lasts days, and mints the PayPlus page at the instant the customer clicks.
 *
 * Everything else here is the discipline that makes it safe to put a credential
 * in somebody's inbox: hash-only storage, one uniform refusal, a window that
 * really ends, a revoke that really works, and a completion stamped by the
 * gateway rather than by somebody opening a page.
 */
final class CardUpdateLinkTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{phone: string, message: string}> */
    public array $texts = [];

    /** @var list<array<string, mixed>> */
    public array $generated = [];

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        SmsSenderFactory::fake(null);
        Tenant::clear();
        parent::tearDown();
    }

    // === The link itself ===

    public function test_the_row_keeps_a_hash_and_never_the_token(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            ['link' => $link, 'url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);

            $raw = $this->tokenFrom($url);

            $this->assertNotSame($raw, $link->token_hash);
            $this->assertSame(CardUpdateLink::hash($raw), $link->token_hash);
            $this->assertStringNotContainsString($raw, json_encode($link->getAttributes()));
        });
    }

    public function test_a_link_resolves_inside_its_window_and_not_after(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            ['link' => $link, 'url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan, ttlDays: 7);

            $this->get($url)->assertOk();

            $this->travel(8)->days();
            $this->get($url)->assertGone();

            $this->assertSame('expired', $link->fresh()->state());
        });
    }

    public function test_a_revoked_link_refuses(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            ['link' => $link, 'url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);

            app(CardUpdateLinks::class)->revokeOpen($plan);

            $this->get($url)->assertGone();
            $this->assertSame('revoked', $link->fresh()->state());
        });
    }

    /** Missing, malformed and expired must be indistinguishable from one another. */
    public function test_every_refusal_looks_the_same(): void
    {
        $shop = $this->shop();

        $unknown = $this->get(route(CardUpdateLinks::ROUTE_SHOW, ['token' => str_repeat('z', 48)]));

        Tenant::run($shop, function () use ($shop, $unknown): void {
            $plan = $this->plan($shop);
            ['url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);
            app(CardUpdateLinks::class)->revokeOpen($plan);

            $revoked = $this->get($url);

            $unknown->assertGone();
            $revoked->assertGone();
            $this->assertSame($unknown->getContent(), $revoked->getContent());
        });
    }

    // === The landing page ===

    public function test_opening_the_page_mints_no_payplus_page(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            ['url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);

            $this->get($url)->assertOk();

            // A mail scanner following the link must not burn a PayPlus page —
            // and a page minted now would be expired before the person clicks.
            $this->assertSame([], $this->generated);
        });
    }

    public function test_the_button_mints_the_payplus_page_and_redirects_to_it(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            ['link' => $link, 'url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);
            $token = $this->tokenFrom($url);

            $this->get($url);
            $response = $this->post(route(CardUpdateLinks::ROUTE_START, ['token' => $token]));

            $response->assertRedirect('https://payplus.example/page/abc');
            $this->assertCount(1, $this->generated);

            // The page carries the LINK id as well as the plan, so the callback
            // closes the right one when the merchant sent two reminders.
            $this->assertSame(
                CardUpdateService::MORE_INFO_PREFIX.$plan->public_id.':'.$link->getKey(),
                $this->generated[0]['more_info'],
            );
        });
    }

    public function test_opening_the_page_is_recorded_but_is_not_a_completion(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            ['link' => $link, 'url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);

            $this->get($url)->assertOk();

            $link->refresh();
            $this->assertNotNull($link->clicked_at);
            $this->assertNull($link->completed_at, 'Plenty of people open the page and then cannot find their card.');
            $this->assertSame('opened', $link->state());
        });
    }

    /** The card being replaced is named; nothing else about the person is. */
    public function test_the_page_shows_the_shop_and_the_card_and_nothing_more(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            ['url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);

            $response = $this->get($url);

            $response->assertOk();
            $response->assertDontSee($plan->customer_email);
            $response->assertDontSee((string) $plan->customer_name);
        });
    }

    // === The callback closes the link ===

    public function test_the_gateway_callback_stamps_the_link_that_produced_it(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        $link = Tenant::run($shop, function () use ($shop): CardUpdateLink {
            $plan = $this->plan($shop);

            return app(CardUpdateLinks::class)->mint($shop, $plan)['link'];
        });

        $plan = Tenant::run($shop, static fn () => InstallmentPlan::query()->findOrFail($link->plan_id));

        $this->postJson('/payplus/cardupdate/callback/'.$shop->callbackToken(), [
            'transaction' => [
                'status_code' => '000',
                'more_info' => CardUpdateService::moreInfoFor($plan, $link),
                'token_uid' => 'tok-new-card',
                'four_digits' => '4242',
                'brand_name' => 'Visa',
            ],
        ])->assertOk();

        $this->assertNotNull($link->fresh()->completed_at);
        $this->assertSame('completed', $link->fresh()->state());
    }

    public function test_a_new_card_on_a_held_plan_is_charged_for_the_cycle_it_owes(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();
        Bus::fake([ChargeJob::class]);

        $link = Tenant::run($shop, function () use ($shop): CardUpdateLink {
            $plan = $this->plan($shop);
            $link = app(CardUpdateLinks::class)->mint($shop, $plan)['link'];

            // Collection gave up on this plan: held on the cycle it owes.
            $plan->transitionTo(PlanStatus::PAUSED);
            $plan->forceFill(['payment_failed_at' => now()->subDays(3)])->save();

            return $link;
        });

        $plan = Tenant::run($shop, static fn () => InstallmentPlan::query()->findOrFail($link->plan_id));

        $this->postJson('/payplus/cardupdate/callback/'.$shop->callbackToken(), [
            'transaction' => [
                'status_code' => '000',
                'more_info' => CardUpdateService::moreInfoFor($plan, $link),
                'token_uid' => 'tok-new-card',
                'four_digits' => '4242',
                'brand_name' => 'Visa',
            ],
        ])->assertOk();

        // The new card is asked for the owed cycle at once — on the worker, not
        // inside the callback — rather than the plan staying paused until a
        // person happens to look.
        Bus::assertDispatched(
            ChargeJob::class,
            static fn (ChargeJob $job): bool => $job->shopId === (int) $shop->getKey()
                && $job->planId === (int) $plan->getKey(),
        );
    }

    /**
     * NOT ONLY HELD PLANS. A customer who fixes their card quickly is usually
     * still inside the retry ladder — the cycle due, its slot waiting for
     * tomorrow's attempt, nothing "held" yet. That is where plan 1282 was, and a
     * new card there used to charge nothing until the ladder came round again.
     */
    public function test_a_new_card_on_a_plan_still_being_retried_is_charged_for_the_due_cycle(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();
        Bus::fake([ChargeJob::class]);

        [$plan, $link] = Tenant::run($shop, function () use ($shop): array {
            $plan = $this->plan($shop);
            $plan->forceFill(['next_charge_at' => now()->subDay()->startOfDay()])->save();
            $plan->transitionTo(PlanStatus::AWAITING_PAYMENT);

            $slot = new InstallmentPayment;
            $slot->forceFill([
                'shop_id' => $shop->getKey(),
                'plan_id' => $plan->getKey(),
                'payment_type' => PaymentType::RECURRING->value,
                'sequence' => 1,
                'amount' => 100,
                'currency' => 'ILS',
                'status' => PaymentStatus::RETRY_SCHEDULED->value,
                'attempt_count' => 1,
                'next_retry_at' => now()->addDay(),
            ])->save();

            return [$plan->fresh(), app(CardUpdateLinks::class)->mint($shop, $plan)['link']];
        });

        $this->postJson('/payplus/cardupdate/callback/'.$shop->callbackToken(), $this->payplusBody($plan, $link, [
            'token' => 'tok-fixed-quickly',
            'four_digits' => '4318',
        ]))->assertOk()->assertJson(['updated' => true]);

        Bus::assertDispatched(
            ChargeJob::class,
            static fn (ChargeJob $job): bool => $job->planId === (int) $plan->getKey(),
        );
    }

    /**
     * NOT ONLY PLANS WE STOPPED OURSELVES. The CSV importer files a member whose
     * source said past_due straight at `failed` — no charge date, no slot, no hold
     * stamp — and neither test above could see one. Plan 995 (16/09) saved a new
     * card here and nothing was charged until an admin pressed the button.
     */
    public function test_a_new_card_on_an_imported_past_due_plan_is_charged(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();
        Bus::fake([ChargeJob::class]);

        [$plan, $link] = Tenant::run($shop, function () use ($shop): array {
            $plan = $this->plan($shop);
            $plan->forceFill([
                'status' => PlanStatus::FAILED->value,
                'next_charge_at' => null,
                'payment_failed_at' => null,
            ])->save();

            return [$plan->fresh(), app(CardUpdateLinks::class)->mint($shop, $plan)['link']];
        });

        $this->postJson('/payplus/cardupdate/callback/'.$shop->callbackToken(), $this->payplusBody($plan, $link, [
            'token' => 'tok-past-due',
            'four_digits' => '9125',
        ]))->assertOk()->assertJson(['updated' => true]);

        Bus::assertDispatched(
            ChargeJob::class,
            static fn (ChargeJob $job): bool => $job->planId === (int) $plan->getKey(),
        );
    }

    /**
     * A `failed` plan whose next cycle is still AHEAD was paid up to it. Charging
     * on a new card would bill that cycle a month early — and the one-charge-a-day
     * guard cannot see a month.
     */
    public function test_a_new_card_on_a_failed_plan_paid_up_to_a_future_cycle_charges_nothing_early(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();
        Bus::fake([ChargeJob::class]);

        [$plan, $link] = Tenant::run($shop, function () use ($shop): array {
            $plan = $this->plan($shop);
            $plan->forceFill([
                'status' => PlanStatus::FAILED->value,
                'next_charge_at' => now()->addWeeks(3)->startOfDay(),
            ])->save();

            return [$plan->fresh(), app(CardUpdateLinks::class)->mint($shop, $plan)['link']];
        });

        $this->postJson('/payplus/cardupdate/callback/'.$shop->callbackToken(), $this->payplusBody($plan, $link, [
            'token' => 'tok-paid-up',
            'four_digits' => '9125',
        ]))->assertOk()->assertJson(['updated' => true]);

        Bus::assertNotDispatched(ChargeJob::class);
    }

    public function test_a_new_card_on_a_healthy_plan_charges_nothing_early(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();
        Bus::fake([ChargeJob::class]);

        $link = Tenant::run($shop, function () use ($shop): CardUpdateLink {
            return app(CardUpdateLinks::class)->mint($shop, $this->plan($shop))['link'];
        });

        $plan = Tenant::run($shop, static fn () => InstallmentPlan::query()->findOrFail($link->plan_id));

        $this->postJson('/payplus/cardupdate/callback/'.$shop->callbackToken(), [
            'transaction' => [
                'status_code' => '000',
                'more_info' => CardUpdateService::moreInfoFor($plan, $link),
                'token_uid' => 'tok-new-card',
                'four_digits' => '4242',
                'brand_name' => 'Visa',
            ],
        ])->assertOk();

        // Nothing is owed, so nothing is asked for: the next cycle bills on its
        // own date, exactly as it would have.
        Bus::assertNotDispatched(ChargeJob::class);
    }

    public function test_a_completed_link_cannot_be_used_again(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            ['link' => $link, 'url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);

            $link->markCompleted();

            // A second card, from somebody who thinks they are confirming the
            // first, is the failure this closes.
            $this->get($url)->assertGone();
        });
    }

    // === The channels ===

    public function test_copying_the_link_sends_nothing(): void
    {
        Mail::fake();
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $result = app(CardUpdateLinkSender::class)
                ->send($shop, $this->plan($shop), CardUpdateLink::CHANNEL_COPY);

            $this->assertTrue($result['ok']);
            $this->assertArrayHasKey('url', $result);
            Mail::assertNothingSent();
        });
    }

    public function test_the_email_channel_sends_the_link_to_the_customer(): void
    {
        Mail::fake();
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);

            $result = app(CardUpdateLinkSender::class)
                ->send($shop, $plan, CardUpdateLink::CHANNEL_EMAIL);

            $this->assertTrue($result['ok']);
            $this->assertSame($plan->customer_email, $result['sent_to']);

            Mail::assertSent(
                CardUpdateLinkMail::class,
                fn (CardUpdateLinkMail $mail): bool => $mail->cardUpdateUrl === $result['url'],
            );
        });
    }

    public function test_a_customer_with_no_email_is_refused_before_a_link_exists(): void
    {
        Mail::fake();
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop, email: '');

            $result = app(CardUpdateLinkSender::class)
                ->send($shop, $plan, CardUpdateLink::CHANNEL_EMAIL);

            $this->assertFalse($result['ok']);
            $this->assertSame(CardUpdateLinkSender::ERR_NO_EMAIL, $result['reason']);
            $this->assertSame(
                0,
                CardUpdateLink::query()->count(),
                'A refusal must not leave an unused credential behind.',
            );
        });
    }

    public function test_sms_is_refused_with_a_reason_the_merchant_can_act_on(): void
    {
        $shop = $this->shop();

        Tenant::run($shop, function () use ($shop): void {
            $result = app(CardUpdateLinkSender::class)
                ->send($shop, $this->plan($shop), CardUpdateLink::CHANNEL_SMS);

            // Not "no phone" — the shop has no SMS account, which is the thing
            // to go and fix.
            $this->assertSame(CardUpdateLinkSender::ERR_SMS_OFF, $result['reason']);
        });
    }

    public function test_the_sms_channel_texts_the_link_when_the_shop_has_sms(): void
    {
        $shop = $this->shop();
        $this->enableSms($shop);
        $this->fakeSms();

        Tenant::run($shop, function () use ($shop): void {
            $result = app(CardUpdateLinkSender::class)
                ->send($shop, $this->plan($shop), CardUpdateLink::CHANNEL_SMS);

            $this->assertTrue($result['ok']);
            $this->assertCount(1, $this->texts);
            $this->assertStringContainsString($result['url'], $this->texts[0]['message']);
        });
    }

    /**
     * A transport that broke leaves the link STANDING — the merchant copies it
     * from the notification and sends it themselves, which beats "try again" on
     * a channel that is down.
     */
    public function test_a_failed_send_still_hands_the_merchant_the_link(): void
    {
        $shop = $this->shop();
        $this->enableSms($shop);
        $this->fakeSms(succeeds: false);

        Tenant::run($shop, function () use ($shop): void {
            $result = app(CardUpdateLinkSender::class)
                ->send($shop, $this->plan($shop), CardUpdateLink::CHANNEL_SMS);

            $this->assertFalse($result['ok']);
            $this->assertSame(CardUpdateLinkSender::ERR_SEND_FAILED, $result['reason']);
            $this->assertArrayHasKey('url', $result);
            $this->assertSame(1, CardUpdateLink::query()->count());
        });
    }

    // === The neutral rail ===

    /**
     * The flow was Woo-only by plumbing, not by nature: a Shopify shop charging
     * through PayPlus vaults the same way and could not use it at all.
     */
    public function test_a_shopify_shop_on_the_payplus_rail_can_send_a_link(): void
    {
        $shop = $this->shop(Shop::PLATFORM_SHOPIFY);
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);

            $this->assertTrue(CardUpdateService::availableFor($shop, $plan));

            ['url' => $url] = app(CardUpdateLinks::class)->mint($shop, $plan);
            $this->get($url)->assertOk();
        });
    }

    // === Fixtures ===

    /**
     * THE LINK BELONGS IN A FIELD, NOT A TOAST.
     *
     * It is shown once — the row keeps only a hash — and a notification is the
     * worst place for a string that must be copied exactly: they stack, they wrap
     * a URL mid-token, and two links become two toasts to tell apart. The modal
     * that replaces the form holds it in a real input.
     */
    public function test_the_minted_link_is_shown_in_a_modal_field(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            $this->actingAs(User::factory()->forShop($shop)->create());

            $component = Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->callAction('sendCardUpdateLink', [
                    'channel' => CardUpdateLink::CHANNEL_COPY,
                    'ttl_days' => CardUpdateLink::DEFAULT_TTL_DAYS,
                ]);

            $url = $component->get('cardLinkUrl');

            $this->assertNotSame('', $url, 'the link must reach the modal');
            $this->assertStringContainsString('/c/card', $url);

            // Sent by hand, so the short-lived PayPlus page is offered too.
            $this->assertSame('https://payplus.example/page/abc', $component->get('cardLinkDirectUrl'));
        });
    }

    /**
     * A link that will be OPENED LATER gets no pre-minted PayPlus page. One minted
     * now is expired by the time the mail is read — the exact bug our own
     * redirect exists to avoid.
     */
    public function test_an_emailed_link_is_not_given_a_pre_minted_payplus_page(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            $this->actingAs(User::factory()->forShop($shop)->create());

            $component = Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->callAction('sendCardUpdateLink', [
                    'channel' => CardUpdateLink::CHANNEL_EMAIL,
                    'ttl_days' => CardUpdateLink::DEFAULT_TTL_DAYS,
                ]);

            $this->assertNotSame('', $component->get('cardLinkUrl'));
            $this->assertSame('', $component->get('cardLinkDirectUrl'));
        });
    }

    /**
     * THE LINK IS REVEALED ON THE PAGE. It used to close the modal and leave
     * nothing behind — Filament will not mount a hidden action, so the second
     * modal never opened and the merchant watched the window shut on the one
     * string they were there for.
     */
    public function test_the_page_reveals_the_link_and_a_whatsapp_message(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            $plan->forceFill(['customer_phone' => '054-462-6386'])->save();
            $this->actingAs(User::factory()->forShop($shop)->create());

            $component = Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->callAction('sendCardUpdateLink', [
                    'channel' => CardUpdateLink::CHANNEL_COPY,
                    'ttl_days' => CardUpdateLink::DEFAULT_TTL_DAYS,
                ]);

            $url = $component->get('cardLinkUrl');
            $message = $component->get('cardLinkMessage');

            $this->assertNotSame('', $url);
            $component->assertSee($url);

            // A short generic line, carrying the link.
            $this->assertStringContainsString($url, $message);

            // wa.me wants 97254…, not 054… — and the message travels encoded.
            $wa = $component->instance()->whatsappUrl();
            $this->assertStringStartsWith('https://wa.me/972544626386?text=', (string) $wa);
            $this->assertStringContainsString(rawurlencode($message), (string) $wa);
        });
    }

    /** An edited message is what the button carries — not the default. */
    public function test_the_whatsapp_link_carries_the_edited_message(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            $plan->forceFill(['customer_phone' => '+972 54 462 6386'])->save();
            $this->actingAs(User::factory()->forShop($shop)->create());

            $component = Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->callAction('sendCardUpdateLink', [
                    'channel' => CardUpdateLink::CHANNEL_COPY,
                    'ttl_days' => CardUpdateLink::DEFAULT_TTL_DAYS,
                ])
                ->set('cardLinkMessage', 'היי דנה, אפשר לעדכן כאן');

            $this->assertStringContainsString(
                rawurlencode('היי דנה, אפשר לעדכן כאן'),
                (string) $component->instance()->whatsappUrl(),
            );
        });
    }

    /** No phone on file: no WhatsApp button, rather than one that opens on nothing. */
    public function test_no_phone_means_no_whatsapp_link(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            $plan->forceFill(['customer_phone' => null])->save();
            $this->actingAs(User::factory()->forShop($shop)->create());

            $component = Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->callAction('sendCardUpdateLink', [
                    'channel' => CardUpdateLink::CHANNEL_COPY,
                    'ttl_days' => CardUpdateLink::DEFAULT_TTL_DAYS,
                ]);

            $this->assertNull($component->instance()->whatsappUrl());
            $component->assertSee(__('card_update.share.no_phone'));
        });
    }

    /** Dismiss puts it away — it was shown once, and that was the contract. */
    public function test_dismissing_clears_the_revealed_link(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            $this->actingAs(User::factory()->forShop($shop)->create());

            Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->callAction('sendCardUpdateLink', [
                    'channel' => CardUpdateLink::CHANNEL_COPY,
                    'ttl_days' => CardUpdateLink::DEFAULT_TTL_DAYS,
                ])
                ->call('dismissCardLink')
                ->assertSet('cardLinkUrl', '')
                ->assertSet('cardLinkMessage', '');
        });
    }

    /**
     * THE MERCHANT'S OWN WORDING WINS, and it is substituted with strtr — never a
     * template engine, because this is text somebody typed into a settings screen
     * and handing that to Blade is remote code execution.
     */
    public function test_the_merchants_own_whatsapp_wording_is_used(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            $this->actingAs(User::factory()->forShop($shop)->create());

            MerchantMailSettings::current()->forceFill([
                'card_update_whatsapp' => '{customer}, {shop} כאן. קישור: {url}',
            ])->save();

            $message = Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->callAction('sendCardUpdateLink', [
                    'channel' => CardUpdateLink::CHANNEL_COPY,
                    'ttl_days' => CardUpdateLink::DEFAULT_TTL_DAYS,
                ])
                ->get('cardLinkMessage');

            $this->assertStringContainsString($shop->name, $message);
            $this->assertStringContainsString($plan->customerLabel(), $message);
            $this->assertStringContainsString('/c/card', $message);
            $this->assertStringNotContainsString('{url}', $message, 'placeholders must be filled');
        });
    }

    /** Blank means "use ours", so the shipped wording keeps improving for them. */
    public function test_a_blank_setting_falls_back_to_the_shipped_wording(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop);
            $this->actingAs(User::factory()->forShop($shop)->create());

            MerchantMailSettings::current()->forceFill(['card_update_whatsapp' => null])->save();

            $message = Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->callAction('sendCardUpdateLink', [
                    'channel' => CardUpdateLink::CHANNEL_COPY,
                    'ttl_days' => CardUpdateLink::DEFAULT_TTL_DAYS,
                ])
                ->get('cardLinkMessage');

            // Ours opens with the shop name and carries the link.
            $this->assertStringContainsString($shop->name, $message);
            $this->assertStringContainsString('/c/card', $message);
            // And says nothing about anything being declined.
            $this->assertStringNotContainsString('נדחה', $message);
        });
    }

    // === Did the update actually happen? ===

    /**
     * THE SHAPE PAYPLUS REALLY SENDS. The token and the card sit inside
     * `data.card_information`, beside `data.customer_uid` — and no path we read
     * looked there, so a customer who completed the page (production, 15/09
     * 11:32, status 000) vaulted nothing and the merchant saw nothing.
     */
    public function test_payplus_real_callback_shape_updates_the_card_and_the_screen_says_so(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();
        [$plan, $link] = $this->planWithLink($shop);

        $this->postJson('/payplus/cardupdate/callback/'.$shop->callbackToken(), $this->payplusBody($plan, $link, [
            'token' => 'tok-real-shape',
            'four_digits' => '2316',
            'expiry_month' => '11',
            'expiry_year' => '30',
            'brand_name' => 'Mastercard',
        ]))->assertOk()->assertJson(['updated' => true]);

        $this->assertSame('completed', $link->fresh()->state());

        Tenant::run($shop, function () use ($shop, $plan): void {
            $method = $plan->fresh()->paymentMethod;
            $this->assertSame('tok-real-shape', $method->payplus_card_token_uid);
            $this->assertSame('2316', $method->card_last_four);
            // "30" is 2030, not the year 30 — or every card reads as expired.
            $this->assertSame(2030, (int) $method->exp_year);

            $this->actingAs(User::factory()->forShop($shop)->create());
            Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->assertSee(__('card_update.outcome.updated_title'))
                ->assertSee('2316');
        });
    }

    /** A callback with no token still has the page id — PayPlus's own record has the card. */
    public function test_a_callback_without_a_token_asks_payplus_for_the_page(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();
        [$plan, $link] = $this->planWithLink($shop);

        Http::fake(['*PaymentPages/ipn*' => Http::response([
            'results' => ['status' => 'success'],
            'data' => [
                'transaction' => ['status_code' => '000'],
                'customer_uid' => 'cust-1',
                'card_information' => ['token' => 'tok-from-ipn', 'four_digits' => '7777'],
            ],
        ])]);

        $this->postJson('/payplus/cardupdate/callback/'.$shop->callbackToken(), $this->payplusBody($plan, $link, []))
            ->assertOk()
            ->assertJson(['updated' => true]);

        Tenant::run($shop, function () use ($plan): void {
            $this->assertSame('tok-from-ipn', $plan->fresh()->paymentMethod?->payplus_card_token_uid);
        });
    }

    /** PayPlus said yes and no card came back from anywhere: the plan SAYS so, in red. */
    public function test_a_success_that_brings_no_card_is_said_on_the_plan(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();
        [$plan, $link] = $this->planWithLink($shop);

        Http::fake(['*' => Http::response(['results' => ['status' => 'success'], 'data' => ['transaction' => ['status_code' => '000']]])]);

        $this->postJson('/payplus/cardupdate/callback/'.$shop->callbackToken(), $this->payplusBody($plan, $link, []))
            ->assertOk()
            ->assertJson(['updated' => false]);

        $this->assertNull($link->fresh()->completed_at, 'nothing was updated, so the link is not "card updated"');

        Tenant::run($shop, function () use ($shop, $plan): void {
            $this->assertNull($plan->fresh()->payment_method_id);
            $this->assertDatabaseHas('activity_events', [
                'plan_id' => $plan->getKey(),
                'kind' => Timeline::KIND_CARD_UPDATE_NOT_SAVED,
            ]);

            $this->actingAs(User::factory()->forShop($shop)->create());
            Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->assertSee(__('card_update.outcome.not_saved_title'))
                ->assertDontSee(__('card_update.outcome.updated_title'));
        });
    }

    /**
     * THE WRONG-PERSON CASE. The merchant sent a link to the wrong customer and
     * revoked it — but the PayPlus page it opened can still be completed, with
     * the wrong customer's card. That card must never be billed for this plan.
     */
    public function test_a_link_revoked_before_the_page_came_back_attaches_nothing(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();
        [$plan, $link] = $this->planWithLink($shop);

        Tenant::run($shop, fn () => $link->fresh()->revoke());

        $this->postJson('/payplus/cardupdate/callback/'.$shop->callbackToken(), $this->payplusBody($plan, $link, [
            'token' => 'tok-strangers-card',
            'four_digits' => '9999',
        ]))->assertOk()->assertJson(['updated' => false]);

        Tenant::run($shop, function () use ($plan): void {
            $this->assertNull($plan->fresh()->payment_method_id);
            $this->assertSame(0, InstallmentPaymentMethod::query()->count());

            $event = ActivityEvent::query()
                ->where('plan_id', $plan->getKey())
                ->where('kind', Timeline::KIND_CARD_UPDATE_NOT_SAVED)
                ->sole();
            $this->assertSame(CardUpdateService::NOT_SAVED_LINK_REVOKED, $event->details['reason']);
        });
    }

    public function test_a_declined_card_is_said_on_the_plan(): void
    {
        $shop = $this->shop();
        $this->fakeGateway();
        [$plan, $link] = $this->planWithLink($shop);

        $body = $this->payplusBody($plan, $link, ['token' => 'tok-x']);
        $body['transaction']['status_code'] = '003';

        $this->postJson('/payplus/cardupdate/callback/'.$shop->callbackToken(), $body)
            ->assertOk()
            ->assertJson(['updated' => false]);

        Tenant::run($shop, function () use ($shop, $plan): void {
            $this->assertNull($plan->fresh()->payment_method_id);
            $this->assertDatabaseHas('activity_events', [
                'plan_id' => $plan->getKey(),
                'kind' => Timeline::KIND_CARD_UPDATE_FAILED,
            ]);

            $this->actingAs(User::factory()->forShop($shop)->create());
            Livewire::test(ViewSubscription::class, ['plan' => $plan->getKey()])
                ->assertSee(__('card_update.outcome.failed_title'));
        });
    }

    /** @return array{0: InstallmentPlan, 1: CardUpdateLink} */
    private function planWithLink(Shop $shop): array
    {
        return Tenant::run($shop, function () use ($shop): array {
            $plan = $this->plan($shop);

            return [$plan, app(CardUpdateLinks::class)->mint($shop, $plan)['link']];
        });
    }

    /**
     * The body PayPlus posts to refURL_callback: the transaction on top, the
     * customer and the card under `data`.
     *
     * @param  array<string, string>  $card  empty = a callback carrying no card block
     * @return array<string, mixed>
     */
    private function payplusBody(InstallmentPlan $plan, CardUpdateLink $link, array $card): array
    {
        return [
            'transaction_type' => 'Charge',
            'transaction' => [
                'uid' => 'txn-1',
                'payment_page_request_uid' => 'pr-1',
                'status_code' => '000',
                'more_info' => CardUpdateService::moreInfoFor($plan, $link),
            ],
            'data' => array_filter([
                'customer_uid' => 'cust-1',
                'card_information' => $card !== [] ? $card : null,
            ]),
        ];
    }

    private function shop(string $platform = Shop::PLATFORM_WOOCOMMERCE): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $platform === Shop::PLATFORM_SHOPIFY ? 'cards.myshopify.com' : null,
            'woocommerce_domain' => $platform === Shop::PLATFORM_WOOCOMMERCE ? 'cards.example.com' : null,
            'name' => 'Cards Co',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => $platform,
            // Only the Woo shop has the legacy token; the Shopify one proves the
            // neutral column stands on its own.
            'wc_shop_token' => $platform === Shop::PLATFORM_WOOCOMMERCE ? 'wc-token-cards' : null,
            'callback_token' => 'cb-token-'.$platform,
        ]);

        $shop->payplus_credentials = [
            'api_key' => 'pk', 'secret_key' => 'sk',
            'terminal_uid' => 't', 'payment_page_uid' => 'pp',
        ];
        $shop->save();

        return $shop->fresh();
    }

    private function plan(Shop $shop, string $email = 'dana@example.com'): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'public_id' => 'PLN-'.uniqid('', true),
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'customer_name' => 'Dana Subscriber',
            'customer_email' => $email,
            'customer_phone' => '0501234567',
            'total_amount' => 0,
            'total_charged' => 100,
            'installment_amount' => 100,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
        ])->save();

        return $plan->fresh();
    }

    private function enableSms(Shop $shop): void
    {
        Tenant::run($shop, static function (): void {
            MerchantSmsSettings::current()->forceFill([
                'enabled' => true,
                'provider' => MerchantSmsSettings::PROVIDER_019,
                'username' => 'acct',
                'api_token' => 'tok',
                'sender' => 'LETS',
            ])->save();
        });
    }

    private function fakeSms(bool $succeeds = true): void
    {
        $test = $this;

        SmsSenderFactory::fake(fn (Shop $shop): SmsSender => new class($test, $succeeds) implements SmsSender
        {
            public function __construct(private object $test, private bool $succeeds) {}

            public function send(string $phone, string $message): bool
            {
                $this->test->texts[] = ['phone' => $phone, 'message' => $message];

                return $this->succeeds;
            }
        });
    }

    private function fakeGateway(): void
    {
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test) implements PayPlusGatewayInterface
        {
            public function __construct(private object $test) {}

            public function generateLink(array $payload): GatewayResult
            {
                $this->test->generated[] = $payload;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success'],
                    'data' => ['payment_page_link' => 'https://payplus.example/page/abc'],
                ]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    private function tokenFrom(string $url): string
    {
        return (string) basename(parse_url($url, PHP_URL_PATH) ?: '');
    }
}
