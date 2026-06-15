<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Root → the admin panel. This is an embedded Shopify admin app; there is no
 * public marketing page. The Filament panel lives at /admin (AdminPanelProvider).
 */
Route::get('/', fn () => redirect('/admin'));

/*
 * DEV-ONLY auto-login for local visual verification (Playwright screenshots).
 * Guarded hard by app()->isLocal() AND config('app.dev_tenant') — it can never
 * exist on a production deploy. Logs in the demo admin and redirects to /admin.
 * Production auth is the embedded-app session-token bridge (shopify-integration).
 */
if (app()->isLocal() && config('app.dev_tenant', false)) {
    Route::get('/dev-login', function () {
        $user = User::query()->orderBy('id')->first();
        if ($user) {
            Auth::login($user);
        }

        return redirect('/admin');
    });
}
