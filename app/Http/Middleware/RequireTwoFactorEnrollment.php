<?php

namespace App\Http\Middleware;

use App\Filament\Pages\TwoFactorSecurity;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor is MANDATORY for platform admins (they reach every shop's money
 * and customers). Until one has enrolled an authenticator app, every panel page
 * load sends them to Account → Security, and nothing else opens.
 *
 * Only full page loads (GET, not a Livewire update) are redirected: a Livewire
 * XHR cannot follow a redirect, and without a rendered page there is no
 * component snapshot to act on anyway. Merchants are untouched — for them the
 * second factor is an opt-in.
 */
final class RequireTwoFactorEnrollment
{
    // === CONSTANTS ===
    /** Livewire marks its own XHRs with this header. */
    public const LIVEWIRE_HEADER = 'X-Livewire';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (
            $user instanceof User
            && $user->mustUseTwoFactor()
            && ! $user->hasTwoFactorEnabled()
            && $request->isMethod('GET')
            && ! $request->hasHeader(self::LIVEWIRE_HEADER)
            && ! $request->routeIs(TwoFactorSecurity::getRouteName())
        ) {
            return redirect(TwoFactorSecurity::getUrl());
        }

        return $next($request);
    }
}
