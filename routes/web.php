<?php

use App\Filament\Resources\ShopResource;
use App\Models\User;
use App\Support\PlatformContext;
use Database\Seeders\DemoShopSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Root → the admin panel. This is an embedded Shopify admin app; there is no
 * public marketing page. The Filament panel lives at /admin (AdminPanelProvider).
 *
 * PRESERVE the query string: Shopify loads the App URL (root) with the embedded
 * entry params (?id_token=…&host=…&shop=…&embedded=1). Forwarding them to /admin
 * lets EmbeddedAuthenticate authenticate on the very first load and App Bridge get
 * its host — a bare redirect('/admin') would drop them and the embedded app would
 * fall back to the login form.
 */
Route::get('/', function () {
    $qs = request()->getQueryString();

    return redirect('/admin'.($qs !== null && $qs !== '' ? '?'.$qs : ''));
});

/*
 * Platform-admin "Exit shop": clear the entered-shop session selection and return
 * to the Shops list. POST (state-changing, CSRF-protected by the web group). The
 * action is a no-op for anyone who is not an entered platform admin — clearing an
 * unset key is harmless, and a merchant can never have set it in a way the
 * middleware honours. Used by the persistent "Viewing as …" banner.
 */
Route::post('/admin/platform/exit', function () {
    PlatformContext::exit();

    return redirect(ShopResource::getUrl('index'));
})->middleware(['web', 'auth'])->name('platform.exit');

/*
 * DEV-ONLY auto-login for local visual verification (Playwright screenshots).
 * Guarded hard by app()->isLocal() AND config('app.dev_tenant') — it can never
 * exist on a production deploy. Logs in the demo admin and redirects to /admin.
 * Production auth is the embedded-app session-token bridge (shopify-integration).
 */
if (app()->isLocal() && config('app.dev_tenant', false)) {
    Route::get('/dev-login', function () {
        // Deterministically log in the demo MERCHANT (not the demo platform admin —
        // see DemoShopSeeder). Seeding order must not change who dev-login picks, so
        // we match by the known demo merchant email and only fall back to the first
        // user if the seed hasn't run. To exercise the platform-admin / Shops flow
        // locally, log in explicitly as DemoShopSeeder::PLATFORM_ADMIN_EMAIL.
        $user = User::query()->where('email', DemoShopSeeder::ADMIN_EMAIL)->first()
            ?? User::query()->orderBy('id')->first();

        if ($user) {
            Auth::login($user);
        }

        return redirect('/admin');
    });
}
