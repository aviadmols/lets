<?php

namespace App\Domain\Auth\TwoFactor;

use App\Models\User;
use Illuminate\Support\Facades\Session;

/**
 * The half-way state between "the password was right" and "the code was right".
 *
 * Nobody is logged in while this is pending: the login page only remembers WHO
 * passed the password (and whether they ticked "remember me"), and the
 * challenge page logs them in after the code. The state lives in the session,
 * expires after TTL_SECONDS, and is cleared on success — so a password alone
 * never produces an authenticated session.
 */
final class PendingTwoFactorLogin
{
    // === CONSTANTS ===
    public const SESSION_KEY = 'auth.two_factor_pending';

    /** How long the code screen stays valid after the password step. */
    public const TTL_SECONDS = 300;

    public static function start(User $user, bool $remember): void
    {
        Session::put(self::SESSION_KEY, [
            'user_id' => (int) $user->getKey(),
            'remember' => $remember,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ]);
    }

    /** The user waiting for their code, or null when none / expired. */
    public static function user(): ?User
    {
        $pending = Session::get(self::SESSION_KEY);
        if (! is_array($pending) || (int) ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $user = User::query()->find((int) ($pending['user_id'] ?? 0));

        return $user instanceof User && $user->hasTwoFactorEnabled() ? $user : null;
    }

    public static function remember(): bool
    {
        return (bool) (Session::get(self::SESSION_KEY)['remember'] ?? false);
    }

    public static function clear(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
