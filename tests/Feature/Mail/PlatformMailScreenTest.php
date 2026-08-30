<?php

namespace Tests\Feature\Mail;

use App\Filament\Pages\ManagePlatformMail;
use App\Mail\Support\MailTransport;
use App\Models\PlatformMailSettings;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform → Email delivery: the owner's screen for the house's sending
 * account.
 *
 * The laws are about a credential and a blast radius:
 *
 *   - IT IS THE OWNER'S SCREEN. A merchant configuring the account every other
 *     merchant sends through is the whole platform's mail in one pair of hands.
 *   - A SAVED KEY IS NEVER SHOWN BACK, and a blank field keeps it — an owner
 *     changing the sender name must not blank the credential the platform sends
 *     with.
 *   - THE ENV VAR STILL WORKS. A deploy configured by variables alone keeps
 *     sending; a key saved on the screen wins, or the form would be a lie.
 */
final class PlatformMailScreenTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const API = 'https://api.sendgrid.com/v3';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.sendgrid.api_key', null);
        Config::set('services.sendgrid.api_base', self::API);
        Config::set('services.sendgrid.from_address', null);
        Config::set('services.sendgrid.smtp_host', 'smtp.sendgrid.net');
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === Who may open it ===

    public function test_only_a_platform_admin_reaches_the_screen(): void
    {
        $shop = $this->shop('platform-mail-gate.example.com');

        $this->actingAs(User::factory()->forShop($shop)->create());
        $this->assertFalse(ManagePlatformMail::canAccess());

        $this->actingAs($this->platformAdmin());
        $this->assertTrue(ManagePlatformMail::canAccess());

        // Not while entered INTO a shop: the account is the house's, and the
        // screen appearing inside a merchant's context would read as theirs.
        Tenant::set($shop);
        $this->assertFalse(ManagePlatformMail::canAccess());
    }

    // === The credential ===

    public function test_the_key_is_stored_encrypted_and_never_shown_back(): void
    {
        $this->actingAs($this->platformAdmin());

        Livewire::test(ManagePlatformMail::class)
            ->set('data.sendgrid_api_key', 'SG.secret-key')
            ->set('data.from_address', 'mail@lets.co.il')
            ->call('save')
            ->assertHasNoErrors()
            // Re-masked the moment it is saved.
            ->assertSet('data.sendgrid_api_key', null);

        $settings = PlatformMailSettings::current();
        $this->assertSame('SG.secret-key', $settings->apiKey());

        // On disk it is ciphertext, not the key.
        $raw = (string) DB::table('platform_mail_settings')
            ->value('sendgrid_api_key');
        $this->assertNotSame('SG.secret-key', $raw);
        $this->assertStringNotContainsString('SG.secret-key', $raw);
    }

    public function test_saving_without_retyping_the_key_keeps_it(): void
    {
        $this->actingAs($this->platformAdmin());

        Livewire::test(ManagePlatformMail::class)
            ->set('data.sendgrid_api_key', 'SG.first')
            ->call('save');

        // An owner changing the sender name must not blank the credential the
        // whole platform sends with.
        Livewire::test(ManagePlatformMail::class)
            ->set('data.from_name', 'LETS')
            ->call('save');

        $settings = PlatformMailSettings::current();
        $this->assertSame('SG.first', $settings->apiKey());
        $this->assertSame('LETS', $settings->from_name);
    }

    public function test_a_saved_key_wins_over_the_environment(): void
    {
        Config::set('services.sendgrid.api_key', 'SG.from-env');

        // With nothing saved, the environment is the answer — a deploy brought
        // up from variables alone keeps sending.
        $this->assertSame('SG.from-env', PlatformMailSettings::current()->apiKey());

        $this->actingAs($this->platformAdmin());
        Livewire::test(ManagePlatformMail::class)
            ->set('data.sendgrid_api_key', 'SG.from-screen')
            ->call('save');

        // Saved wins, or the form would be a value silently ignored.
        $this->assertSame('SG.from-screen', PlatformMailSettings::current()->fresh()->apiKey());
    }

    public function test_the_saved_account_is_what_shops_actually_send_through(): void
    {
        $shop = $this->shop('platform-mail-send.example.com');

        $settings = PlatformMailSettings::current();
        $settings->sendgrid_api_key = 'SG.live';
        $settings->from_address = 'mail@lets.co.il';
        $settings->from_name = 'LETS';
        $settings->save();

        $chosen = MailTransport::for($shop);

        $this->assertSame('smtp.sendgrid.net', $chosen['config']['host']);
        $this->assertSame('SG.live', $chosen['config']['password']);
        $this->assertSame('mail@lets.co.il', $chosen['from']['address']);
    }

    // === The platform's own domain ===

    public function test_the_owner_can_authenticate_the_platform_domain(): void
    {
        $settings = PlatformMailSettings::current();
        $settings->sendgrid_api_key = 'SG.live';
        $settings->save();

        Http::fake([
            self::API.'/whitelabel/domains' => Http::response([
                'id' => 501,
                'domain' => 'lets.co.il',
                'subdomain' => 'mail',
                'dns' => [
                    'mail_cname' => ['host' => 'em1.lets.co.il', 'type' => 'cname', 'data' => 'u1.wl.sendgrid.net'],
                ],
            ], 201),
        ]);

        $this->actingAs($this->platformAdmin());

        Livewire::test(ManagePlatformMail::class)
            ->set('data.domain', 'https://www.LETS.co.il/')
            ->call('requestDomain');

        $settings = PlatformMailSettings::current()->fresh();
        $this->assertSame('lets.co.il', $settings->domain);
        // A provider handle is text: SendGrid issues a number, SES names the
        // domain itself, and one column has to hold both.
        $this->assertSame('501', $settings->provider_domain_id);
        $this->assertSame('mail.lets.co.il', $settings->sendingDomain());
        $this->assertCount(1, $settings->dnsRecords());
    }

    /**
     * The misconfiguration that breaks EVERY shop at once: a fallback From on a
     * domain the account never authenticated. The screen has to say so.
     */
    public function test_a_from_address_off_the_authenticated_domain_is_flagged(): void
    {
        $settings = PlatformMailSettings::current();
        $settings->forceFill([
            'sendgrid_api_key' => 'SG.live',
            'domain' => 'lets.co.il',
            'subdomain' => 'mail',
            'provider_domain_id' => 1,
            'status' => PlatformMailSettings::STATUS_VERIFIED,
            'from_address' => 'mail@lets.co.il',
        ])->save();

        $this->assertTrue($settings->fresh()->fromMatchesDomain());

        $settings->from_address = 'mail@somewhere-else.com';
        $settings->save();

        $this->assertFalse($settings->fresh()->fromMatchesDomain());
    }

    // === Fixtures ===

    // === The test send actually sends ===

    /**
     * The proof button has to survive a REAL send.
     *
     * It was handing Message::to() a Mailables\Address object, which that
     * method does not take — it builds the Symfony address itself from a
     * string. Nothing caught it, because Mail::fake() records the call without
     * ever running the closure that composes the message: the crash lived
     * exactly in the code a faked mailer skips. So this test sends through the
     * ARRAY transport, where the message really is composed.
     */
    public function test_the_test_send_composes_a_real_message(): void
    {
        Config::set('mail.default', 'array');
        $this->shop('platform-mail-send.example.com');
        $this->actingAs($this->platformAdmin());

        Livewire::test(ManagePlatformMail::class)
            ->callAction('sendTest', ['recipient' => 'owner@example.com']);

        $sent = app('mailer')->getSymfonyTransport()->messages();

        $this->assertCount(1, $sent, 'A message was actually composed and handed to the transport.');
        $this->assertStringContainsString(
            'owner@example.com',
            $sent[0]->getOriginalMessage()->getTo()[0]->getAddress(),
        );
    }

    public function test_a_malformed_address_is_refused_by_the_form_before_any_send(): void
    {
        Config::set('mail.default', 'array');
        $this->shop('platform-mail-fail.example.com');
        $this->actingAs($this->platformAdmin());

        Livewire::test(ManagePlatformMail::class)
            ->callAction('sendTest', ['recipient' => 'not-an-address'])
            ->assertHasActionErrors(['recipient']);

        $this->assertCount(
            0,
            app('mailer')->getSymfonyTransport()->messages(),
            'Nothing was handed to the transport.',
        );
    }

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => 'Platform Mail Co',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['shop_id' => null, 'is_platform_admin' => true]);
    }
}
