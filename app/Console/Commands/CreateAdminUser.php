<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Create an admin user with a generated temporary password.
 *
 *   php artisan user:create-admin aviadmols@gmail.com
 *
 * Idempotent: re-running for an existing email PROMOTES that user to platform
 * admin (and clears shop_id) rather than erroring or duplicating. A new
 * temporary password is printed on creation so the admin can log in immediately
 * and change it. Reusing an existing user keeps their password intact.
 */
class CreateAdminUser extends Command
{
    // === CONSTANTS ===
    protected $signature = 'user:create-admin
        {email : The admin user login email}
        {--name=Admin : Display name for a newly created admin}';

    protected $description = 'Create or promote a platform-admin user and output a temporary password.';

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
            $this->line('Password was not changed. Use the password-reset flow if access is needed.');

            return self::SUCCESS;
        }

        $temporaryPassword = Str::random(16);

        $user = new User();
        $user->forceFill([
            'name' => (string) $this->option('name'),
            'email' => $email,
            'password' => Hash::make($temporaryPassword),
            'email_verified_at' => now(),
            'shop_id' => null,
            'is_platform_admin' => true,
        ])->save();

        $this->info('Admin user created successfully.');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $user->id],
                ['Name', $user->name],
                ['Email', $user->email],
                ['Temporary Password', $temporaryPassword],
            ]
        );
        $this->newLine();
        $this->warn('Change this password immediately after first login.');

        return self::SUCCESS;
    }
}
