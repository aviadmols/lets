<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * DEV-ONLY auto-login for local admin viewing / screenshot capture.
 *
 * In production the merchant is authenticated by the embedded-app session-token
 * bridge (shopify-integration) — NEVER auto-signed-in. Locally there is no
 * Shopify session and no human at the keyboard during a screenshot run, so this
 * signs in the demo admin (lowest-id User) when no one is authenticated.
 *
 * HARD GUARD: a no-op unless app()->isLocal() AND config('app.dev_tenant') is
 * true (the exact gates BindDevTenant uses). It can never run on a production
 * deploy. Registered on the admin panel's main middleware stack so it runs
 * BEFORE Filament's Authenticate (which would otherwise redirect a guest).
 */
class DevAutoLogin
{
    // === CONSTANTS ===
    public const CONFIG_FLAG = 'app.dev_tenant';

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isLocal() && config(self::CONFIG_FLAG, false) && Auth::guard('web')->guest()) {
            $user = User::query()->orderBy('id')->first();
            if ($user) {
                Auth::guard('web')->login($user);
            }
        }

        return $next($request);
    }
}
