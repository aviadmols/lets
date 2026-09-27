<?php

namespace App\Filament\Pages\Auth;

use App\Domain\Auth\TwoFactor\PendingTwoFactorLogin;
use App\Domain\Auth\TwoFactor\TwoFactorAuthenticator;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Step two of the admin login: the 6-digit code from the authenticator app, or
 * one of the recovery codes. Reachable only while PendingTwoFactorLogin holds a
 * user who just passed the password step; anyone else is sent back to login.
 *
 * Throttled separately from the password step (MAX_ATTEMPTS a minute), and a
 * wrong-code streak is logged, so guessing the 1-in-a-million code is not a
 * matter of patience.
 */
class TwoFactorChallenge extends SimplePage implements HasForms
{
    use InteractsWithForms;
    use WithRateLimiting;

    // === CONSTANTS ===
    public const ROUTE_NAME = 'filament.admin.auth.two-factor-challenge';

    public const ROUTE_SLUG = 'two-factor-challenge';

    public const MAX_ATTEMPTS = 5;

    /**
     * Wrong codes per USER (any IP) before the pending login is thrown away and
     * the password must be typed again; the count outlives a fresh password step.
     */
    public const MAX_USER_FAILURES = 5;

    public const USER_FAILURE_DECAY_SECONDS = 900;

    public const USER_LIMITER_PREFIX = 'two-factor-challenge:';

    protected static string $view = 'filament.pages.auth.two-factor-challenge';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Toggles the form between the app code and a recovery code. */
    public bool $useRecoveryCode = false;

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        if (PendingTwoFactorLogin::user() === null) {
            PendingTwoFactorLogin::clear();
            redirect(Filament::getLoginUrl());

            return;
        }

        $this->form->fill();
    }

    public static function getUrl(): string
    {
        return route(self::ROUTE_NAME);
    }

    public function toggleRecovery(): void
    {
        $this->useRecoveryCode = ! $this->useRecoveryCode;
        $this->form->fill();
    }

    public function verify(TwoFactorAuthenticator $twoFactor): ?LoginResponse
    {
        try {
            $this->rateLimit(self::MAX_ATTEMPTS);
        } catch (TooManyRequestsException $exception) {
            Notification::make()
                ->title(__('two_factor.challenge.throttled', ['seconds' => $exception->secondsUntilAvailable]))
                ->danger()
                ->send();

            return null;
        }

        $user = PendingTwoFactorLogin::user();
        if ($user === null) {
            PendingTwoFactorLogin::clear();
            $this->redirect(Filament::getLoginUrl());

            return null;
        }

        $limiterKey = self::USER_LIMITER_PREFIX.$user->getKey();
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_USER_FAILURES)) {
            $this->abandon($user->getKey());

            return null;
        }

        $code = (string) ($this->form->getState()['code'] ?? '');

        $passed = $this->useRecoveryCode
            ? $twoFactor->useRecoveryCode($user, $code)
            : $twoFactor->verifyCode($user, $code);

        if (! $passed) {
            RateLimiter::hit($limiterKey, self::USER_FAILURE_DECAY_SECONDS);
            Log::notice('auth.two_factor_failed', [
                'user_id' => $user->getKey(),
                'mode' => $this->useRecoveryCode ? 'recovery' : 'app',
            ]);

            if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_USER_FAILURES)) {
                $this->abandon($user->getKey());

                return null;
            }

            throw ValidationException::withMessages([
                'data.code' => __('two_factor.challenge.invalid'),
            ]);
        }

        RateLimiter::clear($limiterKey);
        $remember = PendingTwoFactorLogin::remember();
        PendingTwoFactorLogin::clear();

        Filament::auth()->login($user, $remember);
        session()->regenerate();

        Log::info('auth.two_factor_passed', ['user_id' => $user->getKey()]);

        return app(LoginResponse::class);
    }

    /** Too many wrong codes: drop the half-login, back to the password. */
    private function abandon(int|string $userId): void
    {
        PendingTwoFactorLogin::clear();
        Log::warning('auth.two_factor_locked', ['user_id' => $userId]);

        Notification::make()
            ->title(__('two_factor.challenge.locked'))
            ->danger()
            ->send();

        $this->redirect(Filament::getLoginUrl());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label($this->useRecoveryCode
                        ? __('two_factor.challenge.recovery_label')
                        : __('two_factor.challenge.code_label'))
                    ->required()
                    ->autofocus()
                    ->autocomplete('one-time-code')
                    ->extraInputAttributes($this->useRecoveryCode
                        ? []
                        : ['inputmode' => 'numeric', 'maxlength' => 7]),
            ])
            ->statePath('data');
    }

    public function getTitle(): string|Htmlable
    {
        return __('two_factor.challenge.title');
    }

    public function getHeading(): string|Htmlable
    {
        return __('two_factor.challenge.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->useRecoveryCode
            ? __('two_factor.challenge.recovery_help')
            : __('two_factor.challenge.code_help');
    }
}
