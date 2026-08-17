<?php

namespace Tests\Feature\Account;

use App\Domain\Account\LoginCodeService;
use App\Mail\LoginCodeMail;
use App\Models\CustomerLoginCode;
use App\Models\MerchantPortalAppearance;
use App\Models\MerchantSmsSettings;
use App\Models\Shop;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsSenderFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sign-in code is the only thing standing between a stranger and somebody's
 * order history, so each test here pins one property of that wall rather than
 * "the happy path works".
 */
final class LoginCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private PinnedLoginCodeService $codes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'codes.myshopify.com',
            'name' => 'Codes',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);

        $settings = MerchantPortalAppearance::current();
        $settings->login_code_enabled = true;
        $settings->login_code_channel = MerchantPortalAppearance::CHANNEL_BOTH;
        $settings->save();

        // The seam: a subclass whose generateCode() returns whatever the current
        // test pinned, so an assertion can present a code the service issued.
        $this->codes = new PinnedLoginCodeService;

        Mail::fake();
        RateLimiter::clear('lets:login-code:shop:'.$this->shop->getKey());
    }

    protected function tearDown(): void
    {
        SmsSenderFactory::fake(null);
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_code_is_stored_hashed_and_the_destination_is_never_readable(): void
    {
        $this->codes->request($this->shop, CustomerLoginCode::CHANNEL_EMAIL, 'dana@example.com');

        $row = CustomerLoginCode::query()->firstOrFail();

        // A database read must not be able to sign anyone in, and must not be a
        // harvestable list of the shop's customers.
        $this->assertNotSame('dana@example.com', $row->destination_hash);
        $this->assertSame(64, strlen((string) $row->destination_hash));
        $this->assertStringNotContainsString('dana', (string) $row->code_hash);
        $this->assertMatchesRegularExpression('/^\$/', (string) $row->code_hash);
    }

    public function test_the_same_address_hashes_differently_per_shop(): void
    {
        $other = Shop::create([
            'shopify_domain' => 'other-codes.myshopify.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $this->assertNotSame(
            CustomerLoginCode::hashDestination((int) $this->shop->getKey(), CustomerLoginCode::CHANNEL_EMAIL, 'dana@example.com'),
            CustomerLoginCode::hashDestination((int) $other->getKey(), CustomerLoginCode::CHANNEL_EMAIL, 'dana@example.com'),
        );
    }

    /**
     * One handset is one destination however the shopper punctuates it. Without
     * this, a code issued for `050-123 4567` cannot be verified as `0501234567`,
     * asking for a new code does not retire the old one, and the per-destination
     * throttle becomes per-FORMATTING — five spellings, five times the SMS spend.
     */
    #[DataProvider('phoneSpellings')]
    public function test_a_phone_number_hashes_the_same_however_it_is_typed(string $typed): void
    {
        $shopId = (int) $this->shop->getKey();

        $this->assertSame(
            CustomerLoginCode::hashDestination($shopId, CustomerLoginCode::CHANNEL_SMS, '0501234567'),
            CustomerLoginCode::hashDestination($shopId, CustomerLoginCode::CHANNEL_SMS, $typed),
        );
    }

    /** @return array<string, list<string>> */
    public static function phoneSpellings(): array
    {
        return [
            'dashes' => ['050-123-4567'],
            'spaces' => ['050 123 4567'],
            'mixed' => ['050-123 4567'],
            'international' => ['+972501234567'],
            'international spaced' => ['+972 50 123 4567'],
            'bare country code' => ['972501234567'],
            'dialled out' => ['00972501234567'],
            'trailing space' => ['0501234567 '],
        ];
    }

    public function test_a_code_asked_for_one_way_verifies_when_typed_another(): void
    {
        $code = $this->issue('050-123 4567', CustomerLoginCode::CHANNEL_SMS);

        // The shopper tidied the number up while waiting for the message. It is
        // the same phone, so it is the same code.
        $this->assertSame(
            LoginCodeService::VERIFIED,
            $this->verify('0501234567', $code, CustomerLoginCode::CHANNEL_SMS),
        );
    }

    public function test_the_per_destination_throttle_counts_one_handset_once(): void
    {
        $spellings = ['0501234567', '050-1234567', '050 123 4567', '+972501234567', '972-50-123-4567'];

        foreach ($spellings as $spelling) {
            $this->codes->request($this->shop, CustomerLoginCode::CHANNEL_SMS, $spelling);
        }

        // Five spellings of one number are five requests against ONE bucket, and
        // each issue retires the last — so exactly one code is live.
        $this->assertSame(count($spellings), CustomerLoginCode::query()->count());
        $this->assertSame(1, CustomerLoginCode::query()->whereNull('consumed_at')->count());
    }

    public function test_yesterdays_codes_are_pruned_and_a_live_one_is_not(): void
    {
        $this->issue('dana@example.com');
        $live = CustomerLoginCode::query()->firstOrFail();

        $stale = $this->issue('yesterday@example.com');
        CustomerLoginCode::query()
            ->where('id', '!=', $live->getKey())
            ->update(['expires_at' => now()->subDays(2)]);

        // The trait only says WHICH rows may go; without the scheduled command
        // nothing deletes them and a table the migration calls short-lived
        // becomes the largest one in the database.
        $this->artisan('model:prune', ['--model' => [CustomerLoginCode::class]])->assertSuccessful();

        $this->assertSame(1, CustomerLoginCode::query()->count());
        $this->assertTrue(CustomerLoginCode::query()->whereKey($live->getKey())->exists());
        $this->assertNotSame('', $stale);
    }

    public function test_the_prune_reaches_every_shops_rows(): void
    {
        $other = Shop::create([
            'shopify_domain' => 'prune-other.myshopify.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::run($other, function (): void {
            MerchantPortalAppearance::current()->forceFill([
                'login_code_enabled' => true,
                'login_code_channel' => MerchantPortalAppearance::CHANNEL_BOTH,
            ])->save();
        });

        $this->codes->request($other, CustomerLoginCode::CHANNEL_EMAIL, 'other@example.com');
        CustomerLoginCode::query()->withoutGlobalScopes()->update(['expires_at' => now()->subDays(2)]);

        // The scheduler runs with NO tenant bound. A prune that respected the
        // tenant scope would silently delete nothing at all.
        Tenant::clear();
        $this->artisan('model:prune', ['--model' => [CustomerLoginCode::class]])->assertSuccessful();
        Tenant::set($this->shop);

        $this->assertSame(0, CustomerLoginCode::query()->withoutGlobalScopes()->count());
    }

    public function test_the_sms_channel_with_no_gateway_account_leaves_a_trace(): void
    {
        Log::spy();

        // The merchant offers SMS but never finished the 019 account: the code is
        // issued and can never arrive. The shopper is still told the same thing —
        // but a silent no-op here is how a shop stays un-signable-into for weeks.
        $this->codes->request($this->shop, CustomerLoginCode::CHANNEL_SMS, '0501234567');

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => $message === 'account.login_code.no_sms_sender')
            ->once();
    }

    public function test_a_correct_code_verifies_once_and_cannot_be_replayed(): void
    {
        $code = $this->issue('dana@example.com');

        $this->assertSame(LoginCodeService::VERIFIED, $this->verify('dana@example.com', $code));
        // The second presentation of the same code is a replay, not a sign-in.
        $this->assertSame(LoginCodeService::REJECTED, $this->verify('dana@example.com', $code));
    }

    public function test_a_wrong_code_is_rejected_and_the_budget_runs_out(): void
    {
        $this->issue('dana@example.com');

        for ($i = 0; $i < LoginCodeService::MAX_ATTEMPTS - 1; $i++) {
            $this->assertSame(LoginCodeService::REJECTED, $this->verify('dana@example.com', '000000'));
        }

        // The last guess exhausts the row; a correct code afterwards is too late.
        $this->assertSame(LoginCodeService::EXHAUSTED, $this->verify('dana@example.com', '000000'));
        $this->assertSame(LoginCodeService::EXHAUSTED, $this->verify('dana@example.com', '123456'));
    }

    public function test_an_expired_code_is_reported_as_expired_not_wrong(): void
    {
        $this->issue('dana@example.com');
        CustomerLoginCode::query()->update(['expires_at' => now()->subMinute()]);

        // "Expired" is worth telling a shopper so they ask for a new code instead
        // of retyping the same one; it says nothing about whether they exist.
        $this->assertSame(LoginCodeService::EXPIRED, $this->verify('dana@example.com', '123456'));
    }

    public function test_asking_for_a_new_code_retires_the_previous_one(): void
    {
        $first = $this->issue('dana@example.com');
        $second = $this->issue('dana@example.com');

        $this->assertNotSame($first, $second);
        $this->assertSame(LoginCodeService::REJECTED, $this->verify('dana@example.com', $first));
        $this->assertSame(LoginCodeService::VERIFIED, $this->verify('dana@example.com', $second));
    }

    public function test_a_throttled_request_still_reports_success(): void
    {
        for ($i = 0; $i < LoginCodeService::MAX_PER_DESTINATION_HOUR + 3; $i++) {
            $this->assertTrue($this->codes->request($this->shop, CustomerLoginCode::CHANNEL_EMAIL, 'dana@example.com'));
        }

        // Throttling must be invisible to the caller — an endpoint that admits it
        // is throttling one address and not another is an enumeration oracle.
        $this->assertSame(
            LoginCodeService::MAX_PER_DESTINATION_HOUR,
            CustomerLoginCode::query()->count(),
        );
    }

    public function test_a_channel_the_merchant_does_not_offer_is_refused(): void
    {
        $settings = MerchantPortalAppearance::current();
        $settings->login_code_channel = MerchantPortalAppearance::CHANNEL_EMAIL;
        $settings->save();

        $this->assertFalse($this->codes->request($this->shop, CustomerLoginCode::CHANNEL_SMS, '0501234567'));
        $this->assertSame(0, CustomerLoginCode::query()->count());
    }

    public function test_code_login_off_means_no_code_at_all(): void
    {
        $settings = MerchantPortalAppearance::current();
        $settings->login_code_enabled = false;
        $settings->save();

        $this->assertFalse($this->codes->request($this->shop, CustomerLoginCode::CHANNEL_EMAIL, 'dana@example.com'));
        $this->assertSame(0, CustomerLoginCode::query()->count());
    }

    public function test_the_email_channel_sends_the_login_code_mail(): void
    {
        $this->codes->request($this->shop, CustomerLoginCode::CHANNEL_EMAIL, 'dana@example.com');

        Mail::assertSent(LoginCodeMail::class, static fn (LoginCodeMail $mail): bool => $mail->hasTo('dana@example.com'));
    }

    public function test_the_sms_channel_uses_the_shops_own_sender(): void
    {
        $sent = [];
        SmsSenderFactory::fake(function () use (&$sent): SmsSender {
            return new class($sent) implements SmsSender
            {
                public function __construct(private array &$sent) {}

                public function send(string $phone, string $message): bool
                {
                    $this->sent[] = [$phone, $message];

                    return true;
                }
            };
        });

        $sms = MerchantSmsSettings::current();
        $sms->forceFill([
            'enabled' => true,
            'username' => 'shop',
            'api_token' => 'token',
            'sender' => 'LETS',
        ])->save();

        $this->codes->request($this->shop, CustomerLoginCode::CHANNEL_SMS, '0501234567');

        $this->assertCount(1, $sent);
        $this->assertSame('0501234567', $sent[0][0]);
        // The code itself must be in the message, and nothing else identifying.
        $this->assertMatchesRegularExpression('/\d{6}/', $sent[0][1]);
    }

    public function test_an_unconfigured_shop_has_no_sms_sender_at_all(): void
    {
        // Default-off: a merchant who never opted in cannot have messages sent
        // (or billed) on their behalf, and the caller cannot mistake that for
        // "delivered" because the factory hands back null rather than a stub.
        $this->assertNull(SmsSenderFactory::for($this->shop));
    }

    // === Helpers ===

    /**
     * Issue a code whose digits the test knows.
     *
     * The service never returns the code it sent — recovering it from the hash
     * is as expensive for a test as for an attacker — so `generateCode()` is the
     * documented substitution seam, and this pins it to a fresh value per call.
     */
    private function issue(string $destination, string $channel = CustomerLoginCode::CHANNEL_EMAIL): string
    {
        $this->codes->pinned = str_pad((string) (++$this->counter), 6, '0', STR_PAD_LEFT);
        $this->codes->request($this->shop, $channel, $destination);

        return $this->codes->pinned;
    }

    private int $counter = 100000;

    private function verify(string $destination, string $code, string $channel = CustomerLoginCode::CHANNEL_EMAIL): string
    {
        return $this->codes->verify($this->shop, $channel, $destination, $code);
    }
}

/** The documented generateCode() seam, pinned so assertions can present a real code. */
final class PinnedLoginCodeService extends LoginCodeService
{
    public string $pinned = '111111';

    protected function generateCode(): string
    {
        return $this->pinned;
    }
}
