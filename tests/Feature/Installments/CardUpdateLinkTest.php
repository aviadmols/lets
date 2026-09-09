<?php

namespace Tests\Feature\Installments;

use App\Domain\Installments\CardUpdateLinks;
use App\Domain\Installments\CardUpdateLinkSender;
use App\Domain\Installments\CardUpdateService;
use App\Domain\Installments\Models\CardUpdateLink;
use App\Mail\CardUpdateLinkMail;
use App\Models\InstallmentPlan;
use App\Models\MerchantSmsSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsSenderFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
