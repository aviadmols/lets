<?php

namespace App\Console\Commands;

use App\Domain\Auth\TwoFactor\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The lost-phone path: switch a user's two-factor OFF so they can enrol a new
 * app. Out-of-band on purpose — no screen can do this for someone else, and a
 * platform admin cannot do it for themselves in the panel (for them 2FA is
 * mandatory; the next page load sends them straight back to enrolment).
 *
 *   php artisan auth:two-factor-reset owner@example.com
 *
 * Run it only after confirming the person's identity by another channel.
 */
class ResetTwoFactor extends Command
{
    // === CONSTANTS ===
    protected $signature = 'auth:two-factor-reset {email : The login email whose second factor is reset}';

    protected $description = 'Turn off a user\'s two-factor sign-in so they can enrol a new authenticator app.';

    public function handle(TwoFactorAuthenticator $twoFactor): int
    {
        $user = User::query()->where('email', trim((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled()) {
            $this->info('Two-factor is not enabled for this user; nothing to reset.');

            return self::SUCCESS;
        }

        $twoFactor->disable($user);
        $this->info("Two-factor reset for [{$user->email}]. They enrol a new app at their next sign-in.");

        return self::SUCCESS;
    }
}
