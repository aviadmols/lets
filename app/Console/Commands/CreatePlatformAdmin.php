<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provision a PLATFORM ADMIN (the app owner — sees the Shops list, may enter any
 * shop). is_platform_admin is GUARDED on the User model (never mass-assignable),
 * so we set it via forceFill — exactly how a privilege flag should be granted:
 * deliberately, out-of-band, never from request input.
 *
 *   php artisan platform:create-admin owner@example.com --name="Platform Owner"
 *
 * Idempotent: re-running for an existing email PROMOTES that user to platform
 * admin (and clears shop_id) rather than erroring or duplicating. A random
 * password is set on creation; the admin claims their login via the password-reset
 * flow (we never print a password). Reusing an existing user keeps their password.
 */
class CreatePlatformAdmin extends Command
{
    // === CONSTANTS ===
    protected $signature = 'platform:create-admin
        {email : The platform admin login email}
        {--name=Platform Admin : Display name for a newly created admin}';

    protected $description = 'Create or promote a platform-admin user (app owner) — guarded is_platform_admin set via forceFill.';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            // Promote in place — never duplicate the user, never touch their password.
            $existing->forceFill([
                'is_platform_admin' => true,
                'shop_id' => null,
            ])->save();

            $this->info("Promoted existing user [{$email}] to platform admin.");

            return self::SUCCESS;
        }

        $user = new User();
        $user->forceFill([
            'name' => (string) $this->option('name'),
            'email' => $email,
            'password' => Hash::make(Str::random(40)), // claimed via password reset
            'email_verified_at' => now(),
            'shop_id' => null,
            'is_platform_admin' => true,
        ])->save();

        $this->info("Created platform admin [{$email}]. Set a password via the reset flow.");

        return self::SUCCESS;
    }
}
