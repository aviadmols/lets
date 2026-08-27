<?php

namespace Tests\Feature\Mail;

use App\Domain\Mail\SenderDomains;
use App\Mail\Support\MailTransport;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Models\ShopSenderDomain;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A merchant's own sending domain on the PLATFORM's provider account.
 *
 * The laws worth a test are the ones whose failure is invisible until it is
 * expensive:
 *
 *   - AN UNVERIFIED DOMAIN IS NEVER A FROM. The provider refuses the message,
 *     and one that somehow left unsigned teaches the receiving world that this
 *     merchant's domain sends unauthenticated mail.
 *   - A MERCHANT'S OWN RELAY OUTRANKS OURS. They took responsibility for their
 *     delivery; the platform arrangement must not quietly override them.
 *   - RE-REQUESTING REPLACES. A shop that typed the wrong domain must not leave
 *     an orphan authenticated on our account forever.
 *   - ONE SHOP'S DOMAIN IS NEVER ANOTHER'S.
 */
final class SenderDomainTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const KEY = 'SG.test-key';

    private const API = 'https://api.sendgrid.com/v3';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.sendgrid.api_key', self::KEY);
        Config::set('services.sendgrid.api_base', self::API);
        Config::set('services.sendgrid.smtp_host', 'smtp.sendgrid.net');
        Config::set('services.sendgrid.smtp_port', 587);
        Config::set('services.sendgrid.smtp_username', 'apikey');
        Config::set('services.sendgrid.from_address', 'mail@lets.co.il');
        Config::set('services.sendgrid.subdomain', 'mail');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === Requesting ===

    public function test_requesting_stores_the_records_the_merchant_must_publish(): void
    {
        $shop = $this->shop('sender-request.example.com');
        $this->fakeAuthenticate(id: 4242);

        $result = app(SenderDomains::class)->request($shop, 'https://WWW.Example.co.il/contact');

        $this->assertTrue($result['ok']);

        $row = ShopSenderDomain::forShop((int) $shop->getKey());
        // A merchant pastes a URL; what reaches the provider is a domain.
        $this->assertSame('example.co.il', $row->domain);
        $this->assertSame(4242, $row->provider_domain_id);
        $this->assertSame(ShopSenderDomain::STATUS_PENDING, $row->status());
        $this->assertSame('mail.example.co.il', $row->sendingDomain());
        $this->assertCount(2, $row->dnsRecords());
    }

    public function test_a_value_that_is_not_a_domain_never_reaches_the_provider(): void
    {
        $shop = $this->shop('sender-junk.example.com');
        Http::fake();

        $result = app(SenderDomains::class)->request($shop, 'not a domain');

        $this->assertFalse($result['ok']);
        $this->assertSame(SenderDomains::REASON_INVALID_DOMAIN, $result['reason']);
        Http::assertNothingSent();
        $this->assertNull(ShopSenderDomain::forShop((int) $shop->getKey()));
    }

    public function test_changing_the_domain_gives_the_old_one_back(): void
    {
        $shop = $this->shop('sender-swap.example.com');
        $this->fakeAuthenticate(id: 11);

        app(SenderDomains::class)->request($shop, 'first.co.il');

        Http::fake([
            self::API.'/whitelabel/domains' => Http::response($this->domainBody(22, 'second.co.il'), 201),
            self::API.'/whitelabel/domains/11' => Http::response([], 204),
        ]);

        app(SenderDomains::class)->request($shop, 'second.co.il');

        // One row, one domain — and the provider was told to release the old id,
        // so an abandoned domain cannot sit authenticated on our account.
        $this->assertSame(1, ShopSenderDomain::acrossAllTenants()->count());
        $this->assertSame('second.co.il', ShopSenderDomain::forShop((int) $shop->getKey())->domain);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/whitelabel/domains/11'));
    }

    public function test_a_provider_outage_leaves_the_shop_where_it_was(): void
    {
        $shop = $this->shop('sender-outage.example.com');
        Http::fake([self::API.'/*' => Http::response([], 500)]);

        $result = app(SenderDomains::class)->request($shop, 'example.co.il');

        $this->assertFalse($result['ok']);
        $this->assertSame(SenderDomains::REASON_PROVIDER_UNREACHABLE, $result['reason']);
        $this->assertNull(ShopSenderDomain::forShop((int) $shop->getKey()));
    }

    // === Checking ===

    public function test_a_passing_check_verifies_the_domain(): void
    {
        $shop = $this->shop('sender-verify.example.com');
        $this->fakeAuthenticate(id: 77);
        app(SenderDomains::class)->request($shop, 'example.co.il');

        Http::fake([
            self::API.'/whitelabel/domains/77/validate' => Http::response([
                'id' => 77,
                'valid' => true,
                'validation_results' => [
                    'mail_cname' => ['host' => 'em1.example.co.il', 'type' => 'cname', 'data' => 'u1.wl.sendgrid.net', 'valid' => true],
                ],
            ], 200),
        ]);

        $result = app(SenderDomains::class)->check($shop);

        $this->assertTrue($result['ok']);
        $row = ShopSenderDomain::forShop((int) $shop->getKey());
        $this->assertTrue($row->isVerified());
        $this->assertNotNull($row->verified_at);
        $this->assertNotNull($row->last_checked_at);
    }

    public function test_a_failing_check_says_so_without_losing_the_records(): void
    {
        $shop = $this->shop('sender-fail.example.com');
        $this->fakeAuthenticate(id: 88);
        app(SenderDomains::class)->request($shop, 'example.co.il');

        Http::fake([
            self::API.'/whitelabel/domains/88/validate' => Http::response(['id' => 88, 'valid' => false], 200),
        ]);

        $result = app(SenderDomains::class)->check($shop);

        $this->assertFalse($result['ok']);
        $this->assertSame(SenderDomains::REASON_RECORDS_MISSING, $result['reason']);

        $row = ShopSenderDomain::forShop((int) $shop->getKey());
        $this->assertSame(ShopSenderDomain::STATUS_FAILED, $row->status());
        // The merchant still needs the records to fix the thing that failed.
        $this->assertCount(2, $row->dnsRecords());
    }

    // === What actually goes on the envelope ===

    public function test_an_unverified_domain_is_never_a_from_address(): void
    {
        $shop = $this->shop('sender-unverified.example.com');
        $this->fakeAuthenticate(id: 99);
        app(SenderDomains::class)->request($shop, 'example.co.il');

        $chosen = MailTransport::for($shop->fresh());

        // The platform address, not the shop's — the provider would refuse the
        // message, and an unsigned one would spend the merchant's own domain
        // reputation on our mistake.
        $this->assertSame('mail@lets.co.il', $chosen['from']['address']);
    }

    public function test_a_verified_domain_becomes_the_from_address(): void
    {
        $shop = $this->shop('sender-live.example.com');
        $this->verifiedDomain($shop, 'example.co.il');

        Tenant::run($shop, function () use ($shop): void {
            $settings = MerchantMailSettings::current();
            $settings->from_address = 'hello@whatever.com';
            $settings->from_name = 'The Shop';
            $settings->save();

            $chosen = MailTransport::for($shop);

            // The local part the merchant asked for, on the domain that is
            // actually theirs to send from — the two settings compose.
            $this->assertSame('hello@mail.example.co.il', $chosen['from']['address']);
            $this->assertSame('The Shop', $chosen['from']['name']);
            $this->assertSame('smtp.sendgrid.net', $chosen['config']['host']);
            $this->assertSame(self::KEY, $chosen['config']['password']);
        });
    }

    public function test_a_merchants_own_relay_outranks_the_platform_account(): void
    {
        $shop = $this->shop('sender-ownsmtp.example.com');
        $this->verifiedDomain($shop, 'example.co.il');

        Tenant::run($shop, function () use ($shop): void {
            $settings = MerchantMailSettings::current();
            $settings->override_env_smtp = true;
            $settings->smtp_host = 'relay.theirhost.co.il';
            $settings->from_address = 'shop@example.co.il';
            $settings->save();

            $chosen = MailTransport::for($shop);

            // They took responsibility for their own delivery; our arrangement
            // does not quietly override them.
            $this->assertSame('relay.theirhost.co.il', $chosen['config']['host']);
            $this->assertSame('shop@example.co.il', $chosen['from']['address']);
        });
    }

    public function test_with_no_platform_account_nothing_changes(): void
    {
        Config::set('services.sendgrid.api_key', null);
        $shop = $this->shop('sender-none.example.com');

        // The platform .env mailer, exactly as before this feature existed.
        $this->assertNull(MailTransport::for($shop));
    }

    public function test_one_shops_domain_is_never_another_shops(): void
    {
        $mine = $this->shop('sender-mine.example.com');
        $theirs = $this->shop('sender-theirs.example.com');

        $this->verifiedDomain($theirs, 'theirs.co.il');

        $this->assertNull(ShopSenderDomain::forShop((int) $mine->getKey()));
        $this->assertSame('mail@lets.co.il', MailTransport::for($mine)['from']['address']);
    }

    // === Fixtures ===

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => 'Sender Co',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    private function verifiedDomain(Shop $shop, string $domain): ShopSenderDomain
    {
        $row = new ShopSenderDomain;
        $row->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'domain' => $domain,
            'subdomain' => 'mail',
            'provider_domain_id' => 1,
            'status' => ShopSenderDomain::STATUS_VERIFIED,
            'records' => [],
            'verified_at' => now(),
        ])->save();

        return $row;
    }

    private function fakeAuthenticate(int $id): void
    {
        Http::fake([
            self::API.'/whitelabel/domains' => Http::response($this->domainBody($id, 'example.co.il'), 201),
        ]);
    }

    /** @return array<string, mixed> */
    private function domainBody(int $id, string $domain): array
    {
        return [
            'id' => $id,
            'domain' => $domain,
            'subdomain' => 'mail',
            'valid' => false,
            'dns' => [
                'mail_cname' => ['host' => 'em1.'.$domain, 'type' => 'cname', 'data' => 'u1.wl.sendgrid.net', 'valid' => false],
                'dkim1' => ['host' => 's1._domainkey.'.$domain, 'type' => 'cname', 'data' => 's1.domainkey.u1.wl.sendgrid.net', 'valid' => false],
            ],
        ];
    }
}
