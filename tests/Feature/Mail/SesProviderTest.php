<?php

namespace Tests\Feature\Mail;

use App\Domain\Mail\SenderDomainProviderFactory;
use App\Domain\Mail\Ses\SesClient;
use App\Domain\Mail\Ses\SignatureV4;
use App\Mail\Support\MailTransport;
use App\Models\PlatformMailSettings;
use App\Models\Shop;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Amazon SES as the platform's sending account.
 *
 * Two things are worth pinning hard. The SIGNATURE, because AWS refuses a
 * request whose signature is a single byte wrong and the error names nothing
 * useful — so it is computed against a fixed key, region and clock and
 * compared to itself. And the CREDENTIAL SPLIT, because SES issues API keys
 * and SMTP credentials separately, and sending with the wrong pair is the
 * classic SES setup failure.
 */
final class SesProviderTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const REGION = 'eu-central-1';

    private const HOST = 'email.eu-central-1.amazonaws.com';

    private const DOMAIN = 'lets.co.il';

    protected function tearDown(): void
    {
        SenderDomainProviderFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    private function sesSettings(): PlatformMailSettings
    {
        $settings = PlatformMailSettings::current();
        $settings->forceFill([
            'provider' => PlatformMailSettings::PROVIDER_SES,
            'ses_region' => self::REGION,
            'ses_access_key_id' => 'AKIAEXAMPLE',
            'ses_secret_access_key' => 'secret-example',
            'ses_smtp_username' => 'AKIASMTPUSER',
            'ses_smtp_password' => 'smtp-password',
        ])->save();

        return $settings->fresh();
    }

    // === The signature ===

    public function test_the_signature_is_deterministic_and_never_carries_the_secret(): void
    {
        $at = CarbonImmutable::parse('2026-09-01T12:00:00Z');
        $signer = new SignatureV4('AKIAEXAMPLE', 'secret-example', self::REGION);

        $first = $signer->headers('GET', self::HOST, '/v2/email/identities', '', $at);
        $second = $signer->headers('GET', self::HOST, '/v2/email/identities', '', $at);

        $this->assertSame($first, $second, 'Same inputs, same signature — always.');
        $this->assertStringContainsString('AWS4-HMAC-SHA256', $first['Authorization']);
        $this->assertStringContainsString('eu-central-1/ses/aws4_request', $first['Authorization']);
        $this->assertSame('20260901T120000Z', $first['X-Amz-Date']);

        // The secret derives the signature and never travels beside it.
        foreach ($first as $value) {
            $this->assertStringNotContainsString('secret-example', $value);
        }
    }

    public function test_a_different_body_or_day_changes_the_signature(): void
    {
        $signer = new SignatureV4('AKIAEXAMPLE', 'secret-example', self::REGION);
        $at = CarbonImmutable::parse('2026-09-01T12:00:00Z');

        $base = $signer->headers('POST', self::HOST, '/v2/email/identities', '{"a":1}', $at);

        $this->assertNotSame(
            $base['Authorization'],
            $signer->headers('POST', self::HOST, '/v2/email/identities', '{"a":2}', $at)['Authorization'],
            'The body is signed.',
        );
        $this->assertNotSame(
            $base['Authorization'],
            $signer->headers('POST', self::HOST, '/v2/email/identities', '{"a":1}', $at->addDay())['Authorization'],
            'A signature is scoped to its day.',
        );
    }

    // === The domain calls ===

    public function test_authenticating_a_domain_returns_the_three_dkim_cnames(): void
    {
        $this->sesSettings();

        Http::fake([
            'https://'.self::HOST.'/v2/email/identities' => Http::response([
                'DkimAttributes' => ['Status' => 'PENDING', 'Tokens' => ['tok1', 'tok2', 'tok3']],
            ], 200),
        ]);

        $result = (new SesClient)->authenticateDomain(self::DOMAIN);

        $this->assertSame(self::DOMAIN, $result['id'], 'SES names the domain itself.');
        $this->assertCount(3, $result['records']);
        $this->assertSame('tok1._domainkey.'.self::DOMAIN, $result['records'][0]['host']);
        $this->assertSame('tok1'.SesClient::DKIM_SUFFIX, $result['records'][0]['data']);
        $this->assertSame('CNAME', $result['records'][0]['type']);
    }

    public function test_a_domain_is_valid_only_when_dkim_passes_and_sending_is_allowed(): void
    {
        $this->sesSettings();

        // DKIM is fine, but SES will not send as it yet.
        Http::fake([
            'https://'.self::HOST.'/v2/email/identities/*' => Http::response([
                'DkimAttributes' => ['Status' => 'SUCCESS', 'Tokens' => ['tok1']],
                'VerifiedForSendingStatus' => false,
            ], 200),
        ]);

        $this->assertFalse((new SesClient)->fetchDomain(self::DOMAIN)['valid']);
    }

    public function test_a_fully_verified_domain_reads_valid(): void
    {
        $this->sesSettings();

        Http::fake([
            'https://'.self::HOST.'/v2/email/identities/*' => Http::response([
                'DkimAttributes' => ['Status' => 'SUCCESS', 'Tokens' => ['tok1']],
                'VerifiedForSendingStatus' => true,
            ], 200),
        ]);

        $this->assertTrue((new SesClient)->validateDomain(self::DOMAIN)['valid']);
    }

    public function test_an_already_existing_identity_is_read_back_not_refused(): void
    {
        $this->sesSettings();

        Http::fake([
            'https://'.self::HOST.'/v2/email/identities' => Http::response(['message' => 'Already exists'], 409),
            'https://'.self::HOST.'/v2/email/identities/*' => Http::response([
                'DkimAttributes' => ['Status' => 'PENDING', 'Tokens' => ['tok1']],
                'VerifiedForSendingStatus' => false,
            ], 200),
        ]);

        $result = (new SesClient)->authenticateDomain(self::DOMAIN);

        $this->assertNotNull($result, 'A domain we already asked for is the domain we want.');
        $this->assertSame(self::DOMAIN, $result['id']);
    }

    public function test_refused_credentials_are_told_apart_from_an_outage(): void
    {
        $this->sesSettings();

        Http::fake(['https://'.self::HOST.'/*' => Http::response(['message' => 'not authorized'], 403)]);

        $client = new SesClient;
        $this->assertNull($client->authenticateDomain(self::DOMAIN));
        $this->assertTrue($client->lastCallWasUnauthorized());
        $this->assertStringContainsString('not authorized', $client->lastError()['message']);
    }

    // === Sending ===

    public function test_the_relay_uses_the_smtp_pair_not_the_api_pair(): void
    {
        $this->sesSettings();

        $shop = Shop::create([
            'woocommerce_domain' => 'ses-relay.example.com',
            'name' => 'SES Co',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $config = MailTransport::for($shop)['config'];

        $this->assertSame('email-smtp.'.self::REGION.'.amazonaws.com', $config['host']);
        $this->assertSame('AKIASMTPUSER', $config['username'], 'The SMTP user, not the access key id.');
        $this->assertSame('smtp-password', $config['password'], 'The SMTP password, not the secret key.');
        $this->assertNotSame('AKIAEXAMPLE', $config['username']);
    }

    public function test_a_shop_is_not_connected_without_the_smtp_pair(): void
    {
        $settings = PlatformMailSettings::current();
        $settings->forceFill([
            'provider' => PlatformMailSettings::PROVIDER_SES,
            'ses_region' => self::REGION,
            'ses_access_key_id' => 'AKIAEXAMPLE',
            'ses_secret_access_key' => 'secret-example',
        ])->save();

        // API credentials alone authenticate domains; they do not send mail.
        $this->assertFalse($settings->fresh()->isConnected());
    }

    public function test_the_factory_follows_the_chosen_provider(): void
    {
        $this->assertNotInstanceOf(SesClient::class, SenderDomainProviderFactory::current());

        $this->sesSettings();

        $this->assertInstanceOf(SesClient::class, SenderDomainProviderFactory::current());
    }
}
