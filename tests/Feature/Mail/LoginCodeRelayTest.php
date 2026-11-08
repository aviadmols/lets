<?php

namespace Tests\Feature\Mail;

use App\Domain\Brand\SafeSiteFetcher;
use App\Filament\Pages\ManageMailSettings;
use App\Mail\LoginCodeMail;
use App\Mail\Support\MailTransport;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\PublicDnsSafeSiteFetcher;
use Tests\TestCase;

/**
 * The sign-in code may leave through the merchant's own Resend account — and
 * ONLY the sign-in code: every other email keeps the regular ladder.
 */
final class LoginCodeRelayTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const KEY = 're_test_123456789';

    private const FROM = 'noreply@relay-shop.example.com';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(SafeSiteFetcher::class, static fn () => new PublicDnsSafeSiteFetcher([]));

        $this->shop = Shop::create([
            'woocommerce_domain' => 'relay-shop.example.com',
            'name' => 'Relay Shop',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === The relay ===

    public function test_without_resend_the_code_follows_the_regular_ladder(): void
    {
        MerchantMailSettings::current();

        $this->assertNull(MailTransport::loginCodeRelay($this->shop));
        $this->assertSame(MailTransport::for($this->shop), MailTransport::forLoginCode($this->shop));
    }

    public function test_with_resend_the_code_leaves_through_resend_with_the_verified_from(): void
    {
        $this->connectResend();

        $relay = MailTransport::forLoginCode($this->shop);

        $this->assertSame('smtp.resend.com', $relay['config']['host']);
        $this->assertSame(587, $relay['config']['port']);
        $this->assertSame('resend', $relay['config']['username']);
        $this->assertSame(self::KEY, $relay['config']['password']);
        $this->assertSame(['address' => self::FROM, 'name' => 'Sella'], $relay['from']);
    }

    public function test_every_other_email_never_leaves_through_resend(): void
    {
        $this->connectResend();

        $this->assertNotSame('smtp.resend.com', MailTransport::for($this->shop)['config']['host'] ?? null);
    }

    public function test_switched_on_without_a_from_is_not_a_relay(): void
    {
        $this->connectResend(from: null);

        $this->assertNull(MailTransport::loginCodeRelay($this->shop));
    }

    public function test_switched_off_the_stored_key_is_not_used(): void
    {
        $this->connectResend(enabled: false);

        $this->assertNull(MailTransport::loginCodeRelay($this->shop));
    }

    public function test_the_key_is_encrypted_at_rest(): void
    {
        $this->connectResend();

        $stored = (string) DB::table('mail_settings')->where('shop_id', $this->shop->getKey())->value('login_code_resend_key');

        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString(self::KEY, $stored);
    }

    public function test_the_code_carries_the_resend_from_even_over_the_smtp_override(): void
    {
        $settings = $this->connectResend();
        $settings->override_env_smtp = true;
        $settings->smtp_host = 'smtp.example.com';
        $settings->from_address = 'billing@other.example.com';
        $settings->save();

        $from = (new LoginCodeMail($this->shop, '123456', 10))->envelope()->from;

        $this->assertSame(self::FROM, $from->address);
        $this->assertSame('Sella', $from->name);
    }

    // === The screen ===

    public function test_the_screen_saves_the_relay_and_keeps_the_key_on_a_blank_save(): void
    {
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(ManageMailSettings::class)
            ->set('data.login_code_via_resend', true)
            ->set('data.login_code_resend_key', self::KEY)
            ->set('data.login_code_from_address', self::FROM)
            ->set('data.login_code_from_name', 'Sella')
            ->call('save')
            ->assertHasNoErrors();

        // A second save with the key field left blank keeps the stored key.
        Livewire::test(ManageMailSettings::class)
            ->assertSet('data.login_code_resend_key', null)
            ->call('save')
            ->assertHasNoErrors();

        $settings = MerchantMailSettings::current()->fresh();
        $this->assertSame(self::KEY, $settings->login_code_resend_key);
        $this->assertTrue($settings->usesLoginCodeRelay());
    }

    public function test_the_screen_refuses_the_relay_half_configured(): void
    {
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(ManageMailSettings::class)
            ->set('data.login_code_via_resend', true)
            ->call('save')
            ->assertHasErrors(['data.login_code_resend_key', 'data.login_code_from_address']);

        $this->assertFalse((bool) MerchantMailSettings::current()->fresh()->login_code_via_resend);
    }

    public function test_the_screen_refuses_something_that_is_not_a_resend_key(): void
    {
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(ManageMailSettings::class)
            ->set('data.login_code_via_resend', true)
            ->set('data.login_code_resend_key', 'SG.this-is-a-sendgrid-key')
            ->set('data.login_code_from_address', self::FROM)
            ->call('save')
            ->assertHasErrors(['data.login_code_resend_key']);
    }

    // === Helpers ===

    private function connectResend(bool $enabled = true, ?string $from = self::FROM): MerchantMailSettings
    {
        $settings = MerchantMailSettings::current();
        $settings->login_code_via_resend = $enabled;
        $settings->login_code_resend_key = self::KEY;
        $settings->login_code_from_address = $from;
        $settings->login_code_from_name = 'Sella';
        $settings->save();

        return $settings;
    }
}
