<?php

namespace App\Filament\Pages\Auth;

use App\Domain\Auth\TwoFactor\PendingTwoFactorLogin;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Log;

/**
 * The admin password login, with a second step for users who turned on
 * two-factor. The password is CHECKED here but, for such a user, nobody is
 * logged in: PendingTwoFactorLogin remembers who passed and the challenge page
 * (TwoFactorChallenge) completes the login after the app code.
 *
 * Users without two-factor sign in exactly as before. Shopify and WordPress
 * embedded sign-ins never pass through here — the platform already
 * authenticated the merchant — so they are unaffected.
 */
class Login extends BaseLogin
{
    // === CONSTANTS ===
    /** Attempts per minute per visitor, the same budget as Filament's own login. */
    public const MAX_ATTEMPTS = 5;

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(self::MAX_ATTEMPTS);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $credentials = $this->getCredentialsFromFormData($data);
        $remember = (bool) ($data['remember'] ?? false);

        $guard = Filament::auth();
        $provider = $guard->getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            $this->throwFailureValidationException();
        }

        if (! $user->canAccessPanel(Filament::getCurrentPanel())) {
            $this->throwFailureValidationException();
        }

        if ($user->hasTwoFactorEnabled()) {
            PendingTwoFactorLogin::start($user, $remember);
            Log::info('auth.two_factor_challenge_started', ['user_id' => $user->getKey()]);

            $this->redirect(TwoFactorChallenge::getUrl());

            return null;
        }

        $guard->login($user, $remember);
        session()->regenerate();

        return app(LoginResponse::class);
    }
}
