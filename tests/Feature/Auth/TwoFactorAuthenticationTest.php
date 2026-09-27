<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\TwoFactor\PendingTwoFactorLogin;
use App\Domain\Auth\TwoFactor\TwoFactorAuthenticator;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\TwoFactorChallenge;
use App\Filament\Pages\TwoFactorSecurity;
use App\Models\Shop;
use App\Models\User;
use Database\Factories\UserFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Two-factor sign-in with an authenticator app. What must hold:
 * - a user with 2FA on is NOT logged in by the password alone;
 * - the right code (once), or a recovery code (once), completes the login;
 * - a platform admin without 2FA opens nothing but Account → Security,
 *   and /horizon stays shut for them;
 * - a merchant without 2FA signs in as before.
 */
final class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function merchant(bool $twoFactor = false): User
    {
        $shop = Shop::create([
            'shopify_domain' => 'tf-'.uniqid().'.myshopify.com',
            'name' => 'TF',
            'status' => Shop::STATUS_ACTIVE,
        ]);

        $factory = User::factory()->forShop($shop);

        return ($twoFactor ? $factory->withTwoFactor() : $factory)->create();
    }

    private function currentCode(): string
    {
        return app(Google2FA::class)->getCurrentOtp(UserFactory::TWO_FACTOR_TEST_SECRET);
    }

    private function passwordStep(User $user): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', self::PASSWORD)
            ->call('authenticate');
    }

    public function test_a_merchant_without_two_factor_signs_in_with_the_password_alone(): void
    {
        $user = $this->merchant();

        $this->passwordStep($user)->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_password_alone_does_not_log_in_a_user_with_two_factor(): void
    {
        $user = $this->merchant(twoFactor: true);

        $this->passwordStep($user)->assertRedirect(TwoFactorChallenge::getUrl());

        $this->assertGuest();
        $this->assertSame($user->id, PendingTwoFactorLogin::user()?->id);
    }

    public function test_a_wrong_password_never_reaches_the_code_step(): void
    {
        $user = $this->merchant(twoFactor: true);

        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'wrong-password')
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertNull(PendingTwoFactorLogin::user());
        $this->assertGuest();
    }

    public function test_the_app_code_completes_the_login_and_cannot_be_replayed(): void
    {
        $user = $this->merchant(twoFactor: true);
        $this->passwordStep($user);
        $code = $this->currentCode();

        Livewire::test(TwoFactorChallenge::class)
            ->set('data.code', $code)
            ->call('verify')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertNull(PendingTwoFactorLogin::user());

        // The same code, a second time, is dead.
        Auth::logout();
        $this->passwordStep($user->fresh());
        Livewire::test(TwoFactorChallenge::class)
            ->set('data.code', $code)
            ->call('verify')
            ->assertHasErrors(['data.code']);
        $this->assertGuest();
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $user = $this->merchant(twoFactor: true);
        $this->passwordStep($user);

        Livewire::test(TwoFactorChallenge::class)
            ->set('data.code', '000000' === $this->currentCode() ? '111111' : '000000')
            ->call('verify')
            ->assertHasErrors(['data.code']);

        $this->assertGuest();
    }

    public function test_too_many_wrong_codes_drop_the_pending_login_whatever_the_ip(): void
    {
        $user = $this->merchant(twoFactor: true);
        $wrong = '000000' === $this->currentCode() ? '111111' : '000000';

        for ($i = 0; $i < TwoFactorChallenge::MAX_USER_FAILURES; $i++) {
            // A fresh password step each time — the per-user count survives it.
            $this->passwordStep($user);
            Livewire::test(TwoFactorChallenge::class)->set('data.code', $wrong)->call('verify');
        }

        $this->assertNull(PendingTwoFactorLogin::user());

        // Even the RIGHT code is refused until the lock decays.
        // Past the one-minute per-IP limits (password page + this page), still
        // inside the per-user lock; the password step is started directly.
        $this->travel(61)->seconds();
        PendingTwoFactorLogin::start($user, false);
        Livewire::test(TwoFactorChallenge::class)
            ->set('data.code', $this->currentCode())
            ->call('verify')
            ->assertRedirect(Filament::getLoginUrl());
        $this->assertGuest();
    }

    public function test_the_challenge_without_a_password_step_goes_back_to_login(): void
    {
        $this->get(TwoFactorChallenge::getUrl())->assertRedirect(Filament::getLoginUrl());
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        $user = $this->merchant();
        $codes = app(TwoFactorAuthenticator::class)->enable(
            $user,
            UserFactory::TWO_FACTOR_TEST_SECRET,
            $this->currentCode(),
        );
        $this->assertCount(TwoFactorAuthenticator::RECOVERY_CODE_COUNT, $codes);
        // Stored hashed, never in the clear.
        $this->assertNotContains($codes[0], $user->fresh()->two_factor_recovery_codes);

        $this->passwordStep($user->fresh());
        Livewire::test(TwoFactorChallenge::class)
            ->call('toggleRecovery')
            ->set('data.code', $codes[0])
            ->call('verify')
            ->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);

        $this->assertFalse(app(TwoFactorAuthenticator::class)->useRecoveryCode($user->fresh(), $codes[0]));
        $this->assertSame(TwoFactorAuthenticator::RECOVERY_CODE_COUNT - 1, app(TwoFactorAuthenticator::class)->remainingRecoveryCodes($user->fresh()));
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $user = $this->merchant(twoFactor: true);

        $raw = \DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame(UserFactory::TWO_FACTOR_TEST_SECRET, $raw);
        $this->assertSame(UserFactory::TWO_FACTOR_TEST_SECRET, $user->fresh()->two_factor_secret);
    }

    public function test_a_platform_admin_without_two_factor_is_sent_to_enrol(): void
    {
        $admin = User::factory()->platformAdmin()->withoutTwoFactor()->create();

        $this->actingAs($admin)->get('/admin')->assertRedirect(TwoFactorSecurity::getUrl());
        $this->actingAs($admin)->get(TwoFactorSecurity::getUrl())->assertOk();

        $this->assertFalse(Gate::forUser($admin)->allows('viewHorizon'));
    }

    public function test_an_enrolled_platform_admin_is_not_redirected(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)->get(TwoFactorSecurity::getUrl());

        $response->assertOk();
        $this->assertTrue(Gate::forUser($admin)->allows('viewHorizon'));
    }

    public function test_enrolment_needs_a_live_code_and_a_platform_admin_cannot_turn_it_off(): void
    {
        $admin = User::factory()->platformAdmin()->withoutTwoFactor()->create();
        $this->actingAs($admin);

        $page = Livewire::test(TwoFactorSecurity::class)->call('startSetup');
        $secret = session(TwoFactorSecurity::SETUP_SECRET_KEY);
        $this->assertIsString($secret);

        $page->set('data.code', '123')->call('confirmSetup')->assertHasErrors(['data.code']);
        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());

        $page->set('data.code', app(Google2FA::class)->getCurrentOtp($secret))
            ->call('confirmSetup')
            ->assertHasNoErrors();
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
        $this->assertCount(TwoFactorAuthenticator::RECOVERY_CODE_COUNT, $page->get('freshRecoveryCodes'));

        $page->call('disable');
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_a_merchant_turns_it_off_only_with_a_current_code(): void
    {
        $user = $this->merchant(twoFactor: true);
        $this->actingAs($user);

        Livewire::test(TwoFactorSecurity::class)
            ->set('data.code', '000000' === $this->currentCode() ? '111111' : '000000')
            ->call('disable')
            ->assertHasErrors(['data.code']);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        Livewire::test(TwoFactorSecurity::class)
            ->set('data.code', $this->currentCode())
            ->call('disable')
            ->assertHasNoErrors();
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_reset_command_clears_the_second_factor(): void
    {
        $user = $this->merchant(twoFactor: true);

        $this->artisan('auth:two-factor-reset', ['email' => $user->email])->assertSuccessful();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }
}
