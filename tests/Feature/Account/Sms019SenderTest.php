<?php

namespace Tests\Feature\Account;

use App\Models\MerchantSmsSettings;
use App\Models\Shop;
use App\Services\Sms\Sms019Sender;
use App\Services\Sms\SmsSenderFactory;
use App\Support\PhoneNumber;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The 019 gateway is the only way an Israeli shopper's sign-in code reaches their
 * phone, and it is the one leg of the flow that leaves our infrastructure. These
 * tests pin the wire format and the failure behaviour, because a send that quietly
 * fails looks exactly like a send that worked — the caller is told "sent" either
 * way, by design, so the only place the truth can live is here.
 */
final class Sms019SenderTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'sms.myshopify.com',
            'name' => 'SMS',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_it_posts_the_shape_019_documents(): void
    {
        Http::fake([Sms019Sender::ENDPOINT => Http::response(['status' => 0], 200)]);

        $sent = (new Sms019Sender('shop-user', 'secret-token', 'LETS'))
            ->send('050-123 4567', 'Your code is 123456.');

        $this->assertTrue($sent);

        Http::assertSent(static function (Request $request): bool {
            $body = $request->data();

            return $request->url() === Sms019Sender::ENDPOINT
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && $body['sms']['user']['username'] === 'shop-user'
                && $body['sms']['source'] === 'LETS'
                // Normalised on the way out: 019 wants a local number, and the
                // shopper typed whatever their contacts app gave them.
                && $body['sms']['destinations']['phone'] === '0501234567'
                && $body['sms']['message'] === 'Your code is 123456.';
        });
    }

    public function test_a_non_zero_status_is_a_failure_even_on_http_200(): void
    {
        // 019 answers 200 OK with a status field. Reading only the HTTP code would
        // report every rejected message — bad credentials, unknown sender name,
        // empty balance — as delivered.
        Http::fake([Sms019Sender::ENDPOINT => Http::response(['status' => 953, 'message' => 'invalid source'], 200)]);

        $this->assertFalse((new Sms019Sender('shop-user', 'secret-token', 'LETS'))->send('0501234567', 'x'));
    }

    public function test_a_transport_failure_does_not_escape(): void
    {
        // The request that asked for a code has already answered the shopper. An
        // exception here would turn a dead gateway into a 500 on the login page.
        Http::fake(static function (): void {
            throw new \RuntimeException('connection refused');
        });

        $this->assertFalse((new Sms019Sender('shop-user', 'secret-token', 'LETS'))->send('0501234567', 'x'));
    }

    public function test_an_unusable_number_is_never_sent(): void
    {
        Http::fake();

        $this->assertFalse((new Sms019Sender('shop-user', 'secret-token', 'LETS'))->send('not a phone', 'x'));

        Http::assertNothingSent();
    }

    public function test_a_long_message_is_truncated_rather_than_rejected(): void
    {
        Http::fake([Sms019Sender::ENDPOINT => Http::response(['status' => 0], 200)]);

        (new Sms019Sender('shop-user', 'secret-token', 'LETS'))
            ->send('0501234567', str_repeat('a', Sms019Sender::MAX_MESSAGE + 500));

        Http::assertSent(static fn (Request $request): bool => mb_strlen($request->data()['sms']['message']) === Sms019Sender::MAX_MESSAGE);
    }

    public function test_the_factory_builds_a_019_sender_from_the_shops_own_credentials(): void
    {
        $sms = MerchantSmsSettings::current();
        $sms->forceFill([
            'enabled' => true,
            'username' => 'shop-user',
            'api_token' => 'secret-token',
            'sender' => 'LETS',
        ])->save();

        // The arm of the factory that actually constructs the gateway — the whole
        // reason the credentials are encrypted per shop.
        $sender = SmsSenderFactory::for($this->shop);

        $this->assertInstanceOf(Sms019Sender::class, $sender);

        Http::fake([Sms019Sender::ENDPOINT => Http::response(['status' => 0], 200)]);
        $sender->send('0501234567', 'x');

        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-token'));
    }

    public function test_a_half_configured_account_sends_nothing(): void
    {
        $sms = MerchantSmsSettings::current();
        // Enabled, with a username, but no token and no sender name. Half a
        // credential is not a credential; the shop simply has no SMS channel.
        $sms->forceFill(['enabled' => true, 'username' => 'shop-user'])->save();

        $this->assertNull(SmsSenderFactory::for($this->shop));
    }

    public function test_a_sender_name_019_would_reject_is_refused_here_first(): void
    {
        $sms = MerchantSmsSettings::current();
        $sms->forceFill([
            'enabled' => true,
            'username' => 'shop-user',
            'api_token' => 'secret-token',
            // Punctuation and more than 11 characters: 019 rejects both, and
            // discovering that as a failed send costs a shopper their sign-in.
            'sender' => 'My Shop Ltd.',
        ])->save();

        $this->assertNull($sms->senderName());
        $this->assertNull(SmsSenderFactory::for($this->shop));
    }

    public function test_every_spelling_of_one_number_normalises_to_the_same_handset(): void
    {
        foreach (['0501234567', '050-123-4567', '050 123 4567', '+972501234567', '+972-50-123-4567', '972501234567', '00972501234567'] as $typed) {
            $this->assertSame('0501234567', PhoneNumber::canonical($typed), $typed.' should be one handset');
        }
    }

    public function test_something_that_is_not_a_number_is_not_guessed_at(): void
    {
        foreach (['', 'call me', '123', '05012345678901234567'] as $typed) {
            $this->assertNull(PhoneNumber::canonical($typed), $typed.' should not become a phone number');
        }
    }
}
